<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitPathGuard.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitCommand.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackSettings.php';

/**
 * Thrown when a clone folder is not one this plugin can prove it made for this stack.
 */
final class GitCloneNotOwnedException extends RuntimeException
{
}

/**
 * The plugin-owned git clone behind one git-backed stack.
 *
 * The clone is always checked out detached at a specific commit, and the
 * plugin never commits to it. The whole repository is checked out: a sparse
 * checkout of just the stack's folder would be smaller, but git then
 * overwrites untracked files without warning, while a plain checkout refuses
 * to. Never switch to a sparse or forced checkout: that refusal is what keeps
 * untracked files safe. Ignored files count as untracked here: by default git
 * overwrites them, so every checkout passes --no-overwrite-ignore. Rules this
 * class keeps (see the tests):
 *  - it only changes a folder that carries this stack's marker and points at
 *    this stack's repository (assertOwned()),
 *  - it only clones into a folder that does not exist yet, and on failure
 *    removes only the folder it just created,
 *  - it never runs git clean and never forces a checkout, so untracked files,
 *    ignored or not, such as data a container wrote through a relative bind
 *    mount, are never removed or overwritten,
 *  - it refuses to check out while tracked files have local changes.
 */
final class GitClone
{
    /** File inside .git that marks a clone as made by this plugin for one stack. */
    public const MARKER_FILE = 'compose-manager-clone';

    private const CLONE_TIMEOUT_SECONDS = 900;
    private const FETCH_TIMEOUT_SECONDS = 300;
    private const REMOTE_TIMEOUT_SECONDS = 60;

    public function __construct(private readonly GitStackSettings $settings)
    {
    }

    public function settings(): GitStackSettings
    {
        return $this->settings;
    }

    /**
     * Whether something exists at the clone folder path (a folder, file or symlink).
     */
    public function exists(): bool
    {
        return file_exists($this->settings->cloneDir) || @readlink($this->settings->cloneDir) !== false;
    }

    /**
     * Ask the remote which commit the branch points at, without changing anything.
     *
     * @throws RuntimeException if the remote cannot be reached or the branch does not exist
     */
    public function remoteBranchCommit(): string
    {
        $ref = 'refs/heads/' . $this->settings->branch;
        $result = $this->git(
            ['ls-remote', '--heads', '--', $this->settings->url, $ref],
            null,
            self::REMOTE_TIMEOUT_SECONDS
        );
        if (!$result->succeeded()) {
            throw new RuntimeException('Could not reach the repository: ' . $result->errorSummary());
        }
        foreach (explode("\n", trim($result->stdout)) as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (is_array($fields) && count($fields) === 2 && $fields[1] === $ref && self::isCommitId($fields[0])) {
                return $fields[0];
            }
        }
        throw new RuntimeException("The branch '{$this->settings->branch}' does not exist in the repository.");
    }

    /**
     * Clone the repository into the stack's clone folder, checked out at the
     * branch's current commit.
     *
     * The folder must not exist yet. If anything fails, the folder this call
     * created is removed again, and nothing else is touched.
     *
     * @return string the commit checked out
     * @throws RuntimeException naming the problem
     */
    public function create(): string
    {
        $cloneDir = $this->settings->cloneDir;
        $clonesRoot = dirname($cloneDir);
        GitPathGuard::assertValidClonesRoot($clonesRoot);
        GitPathGuard::createDirectory($clonesRoot);

        GitPathGuard::assertSafeToWrite($cloneDir);
        if ($this->exists()) {
            throw new RuntimeException("$cloneDir already exists, so nothing was cloned into it.");
        }

        try {
            $args = ['clone', '--no-checkout', '--single-branch', '--no-tags', '--branch', $this->settings->branch];
            if ($this->isLocalRepository()) {
                // --no-local makes git copy objects the normal way instead of
                // hard-linking them, and lets the blob filter below apply.
                $args[] = '--no-local';
            }
            $args[] = '--filter=blob:none';
            $args[] = '--';
            $args[] = $this->settings->url;
            $args[] = $cloneDir;

            $result = $this->git($args, null, self::CLONE_TIMEOUT_SECONDS);
            if (!$result->succeeded()) {
                throw new RuntimeException('Could not clone the repository: ' . $result->errorSummary());
            }
            if (!is_dir($cloneDir . '/.git')) {
                throw new RuntimeException("git reported success but $cloneDir/.git is missing.");
            }

            GitPathGuard::assertSafeToWrite($cloneDir . '/.git');
            if (file_put_contents($cloneDir . '/.git/' . self::MARKER_FILE, $this->settings->cloneId . "\n") === false) {
                throw new RuntimeException("Could not write the clone marker in $cloneDir.");
            }

            $commit = $this->resolveCommit('refs/remotes/origin/' . $this->settings->branch);
            $this->runOrThrow(['checkout', '--detach', $commit], 'Could not check out the files');
            return $commit;
        } catch (Throwable $error) {
            $this->removeFolderCreatedByThisRun();
            throw $error instanceof RuntimeException ? $error : new RuntimeException($error->getMessage(), 0, $error);
        }
    }

    /**
     * Throw unless the clone folder is provably this stack's plugin-made clone.
     *
     * Checks that it is a real folder (not a symlink), that it is the top of a
     * git work tree with its own .git folder, that the marker names this
     * stack's clone id, and that its remote is still this stack's repository.
     *
     * @throws GitCloneNotOwnedException naming the mismatch
     */
    public function assertOwned(): void
    {
        $cloneDir = $this->settings->cloneDir;
        if (@readlink($cloneDir) !== false) {
            throw new GitCloneNotOwnedException("$cloneDir is a symlink, so it was not used.");
        }
        if (!is_dir($cloneDir)) {
            throw new GitCloneNotOwnedException("The clone folder $cloneDir is missing. (Is the array started?)");
        }
        $gitDir = $cloneDir . '/.git';
        if (@readlink($gitDir) !== false || !is_dir($gitDir)) {
            throw new GitCloneNotOwnedException("$cloneDir is not a git clone made by this plugin (no .git folder).");
        }

        $marker = @file_get_contents($gitDir . '/' . self::MARKER_FILE);
        if ($marker === false || trim($marker) !== $this->settings->cloneId) {
            throw new GitCloneNotOwnedException(
                "$cloneDir was not made by this plugin for this stack (its marker is missing or names another stack), so it was not changed."
            );
        }

        $topLevel = $this->git(['rev-parse', '--show-toplevel'], $cloneDir, 30);
        if (!$topLevel->succeeded() || trim($topLevel->stdout) !== realpath($cloneDir)) {
            throw new GitCloneNotOwnedException("$cloneDir is not the top of its own git clone.");
        }

        $remote = $this->git(['config', '--get', 'remote.origin.url'], $cloneDir, 30);
        if (!$remote->succeeded() || trim($remote->stdout) !== $this->settings->url) {
            throw new GitCloneNotOwnedException(
                "$cloneDir is a clone of a different repository than this stack's ("
                . trim($remote->stdout) . '). Clone it again to change the repository.'
            );
        }
    }

    /**
     * Fetch the stack's branch from the remote. Changes only .git, never the files.
     *
     * @return string the commit the branch now points at
     * @throws RuntimeException naming the problem
     */
    public function fetch(): string
    {
        $this->assertOwned();
        GitPathGuard::assertSafeToWrite($this->settings->cloneDir . '/.git');

        $branch = $this->settings->branch;
        $result = $this->git(
            ['fetch', '--no-tags', '--prune', 'origin', '--', "+refs/heads/$branch:refs/remotes/origin/$branch"],
            $this->settings->cloneDir,
            self::FETCH_TIMEOUT_SECONDS
        );
        if (!$result->succeeded()) {
            throw new RuntimeException('Could not fetch from the repository: ' . $result->errorSummary());
        }
        return $this->resolveCommit('refs/remotes/origin/' . $branch);
    }

    /**
     * The commit the files are checked out at.
     *
     * @throws RuntimeException if it cannot be read
     */
    public function checkedOutCommit(): string
    {
        $this->assertOwned();
        return $this->resolveCommit('HEAD');
    }

    /**
     * Tracked files that differ from the checked-out commit, as paths relative to the clone.
     *
     * Untracked files (including data a container wrote into the clone) are
     * not local changes.
     *
     * @return string[]
     * @throws RuntimeException if git status fails
     */
    public function locallyChangedFiles(): array
    {
        $this->assertOwned();
        $result = $this->git(
            ['status', '--porcelain=v1', '-z', '--untracked-files=no'],
            $this->settings->cloneDir,
            60
        );
        if (!$result->succeeded()) {
            throw new RuntimeException('Could not read the clone status: ' . $result->errorSummary());
        }

        $paths = [];
        $entries = explode("\0", $result->stdout);
        for ($i = 0; $i < count($entries); $i++) {
            $entry = $entries[$i];
            if (strlen($entry) < 4) {
                continue;
            }
            $paths[] = substr($entry, 3);
            // A rename or copy is followed by its original path as a separate entry.
            if ($entry[0] === 'R' || $entry[0] === 'C') {
                $i++;
            }
        }
        return $paths;
    }

    /**
     * Whether the stack's files would change between two commits: the folder
     * holding the compose file, or anything at all when it is at the top level.
     *
     * @throws RuntimeException if either commit is unknown
     */
    public function stackFilesChanged(string $fromCommit, string $toCommit): bool
    {
        $this->assertOwned();
        $args = ['diff', '--name-only', '-z', $this->commitArgument($fromCommit), $this->commitArgument($toCommit), '--'];
        $stackFolder = $this->stackFolderInRepo();
        if ($stackFolder !== '') {
            $args[] = $stackFolder;
        }
        $result = $this->git($args, $this->settings->cloneDir, 60);
        if (!$result->succeeded()) {
            throw new RuntimeException('Could not compare commits: ' . $result->errorSummary());
        }
        return trim($result->stdout, "\0") !== '';
    }

    /**
     * Whether the compose file exists at a commit.
     *
     * @throws RuntimeException if the commit is unknown
     */
    public function composeFileExistsAt(string $commit): bool
    {
        $this->assertOwned();
        $result = $this->git(
            ['ls-tree', '--name-only', '-z', $this->commitArgument($commit), '--', $this->settings->composePath],
            $this->settings->cloneDir,
            60
        );
        if (!$result->succeeded()) {
            throw new RuntimeException('Could not read the commit: ' . $result->errorSummary());
        }
        return trim($result->stdout, "\0") === $this->settings->composePath;
    }

    /**
     * Check out a commit. Refuses when tracked files have local changes, and
     * stops (changing nothing) if it would overwrite an untracked file,
     * ignored or not.
     *
     * @throws RuntimeException naming the problem
     */
    public function checkOut(string $commit): void
    {
        $this->assertOwned();
        $changed = $this->locallyChangedFiles();
        if ($changed !== []) {
            throw new RuntimeException(
                'The clone has local changes (' . implode(', ', array_slice($changed, 0, 5))
                . (count($changed) > 5 ? ', ...' : '') . '). Save or discard them first.'
            );
        }
        GitPathGuard::assertSafeToWrite($this->settings->cloneDir);

        // A plain checkout (never sparse, never forced) refuses by itself to
        // overwrite an untracked file, to replace one with a folder, or to
        // write through an untracked symlink, and then changes nothing.
        // Without --no-overwrite-ignore it silently overwrites ignored files
        // (such as a data/ folder listed in .gitignore), so that is always given.
        $result = $this->git(
            ['checkout', '--detach', '--no-overwrite-ignore', $this->commitArgument($commit)],
            $this->settings->cloneDir
        );
        if ($result->succeeded()) {
            return;
        }
        $inTheWay = self::untrackedPathsGitRefusedToOverwrite($result->stderr);
        if ($inTheWay !== []) {
            throw new RuntimeException(
                'The new commit adds files where untracked local files already are, so nothing was changed: '
                . implode(', ', array_slice($inTheWay, 0, 5)) . (count($inTheWay) > 5 ? ', ...' : '')
                . '. Move them out of the way, then deploy again.'
            );
        }
        throw new RuntimeException("Could not check out $commit: " . $result->errorSummary());
    }

    /**
     * The paths git lists when it refuses a checkout over untracked files.
     *
     * git prints "error: The following untracked working tree files would be
     * overwritten by checkout:" (or "removed"), or, for a folder the new commit
     * replaces with a file, "error: Updating the following directories would
     * lose untracked files in them:", then one tab-indented path per line.
     *
     * @return string[]
     */
    private static function untrackedPathsGitRefusedToOverwrite(string $stderr): array
    {
        $paths = [];
        $inList = false;
        foreach (explode("\n", $stderr) as $line) {
            if (str_contains($line, 'untracked working tree files would be')
                || str_contains($line, 'would lose untracked files in them')) {
                $inList = true;
                continue;
            }
            if ($inList && str_starts_with($line, "\t")) {
                $paths[] = trim($line);
                continue;
            }
            $inList = false;
        }
        return $paths;
    }

    /**
     * The folder in the repository that holds the compose file, or '' for the top level.
     */
    public function stackFolderInRepo(): string
    {
        $folder = dirname($this->settings->composePath);
        return $folder === '.' ? '' : $folder;
    }

    /**
     * Whether a string looks like a full commit id (SHA-1 or SHA-256).
     */
    public static function isCommitId(string $value): bool
    {
        return preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/', $value) === 1;
    }

    /**
     * Resolve a reference to a full commit id.
     *
     * @throws RuntimeException if it does not name a commit
     */
    private function resolveCommit(string $reference): string
    {
        $result = $this->git(
            ['rev-parse', '--verify', '--quiet', '--end-of-options', $reference . '^{commit}'],
            $this->settings->cloneDir,
            30
        );
        $commit = trim($result->stdout);
        if (!$result->succeeded() || !self::isCommitId($commit)) {
            throw new RuntimeException("Could not find commit $reference in the clone.");
        }
        return $commit;
    }

    /**
     * A commit id checked to be safe to pass to git as an argument.
     *
     * @throws InvalidArgumentException
     */
    private function commitArgument(string $commit): string
    {
        if (!self::isCommitId($commit)) {
            throw new InvalidArgumentException("Not a full commit id: $commit");
        }
        return $commit;
    }

    private function isLocalRepository(): bool
    {
        return str_starts_with($this->settings->url, '/');
    }

    /**
     * Run git for this clone.
     *
     * A repository on this server (such as one a Gitea container keeps under
     * appdata) is often owned by another user, and git refuses to read from
     * it ("dubious ownership"). The stack's own repository path is trusted
     * for the run, and nothing else (see GitCommand).
     *
     * @param string[] $args
     */
    private function git(array $args, ?string $workingDirectory, int $timeoutSeconds = GitCommand::DEFAULT_TIMEOUT_SECONDS): ProcessResult
    {
        $trusted = $this->isLocalRepository() ? [$this->settings->url] : [];
        return GitCommand::run($args, $workingDirectory, $timeoutSeconds, [], $trusted);
    }

    /**
     * @param string[] $args
     * @throws RuntimeException
     */
    private function runOrThrow(array $args, string $failureMessage): ProcessResult
    {
        $result = $this->git($args, $this->settings->cloneDir);
        if (!$result->succeeded()) {
            throw new RuntimeException("$failureMessage: " . $result->errorSummary());
        }
        return $result;
    }

    /**
     * Remove the clone folder after a failed create().
     *
     * Only called by create(), which checked the folder did not exist before
     * it started. Deletes without following symlinks, and only a folder that
     * is directly inside the clones root.
     */
    private function removeFolderCreatedByThisRun(): void
    {
        $cloneDir = $this->settings->cloneDir;
        if (!$this->exists()) {
            return;
        }
        $parent = realpath(dirname($cloneDir));
        if ($parent === false || @readlink($cloneDir) !== false || dirname((string) realpath($cloneDir)) !== $parent) {
            return;
        }
        self::removeTreeWithoutFollowingLinks($cloneDir);
    }

    private static function removeTreeWithoutFollowingLinks(string $path): void
    {
        if (@readlink($path) !== false || !is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTreeWithoutFollowingLinks($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
