<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/CredentialVault.php';

/**
 * Hands a git stack's HTTPS token to git for one run.
 *
 * The token is kept in the credential vault. For a run that talks to the
 * repository (clone, fetch, ls-remote), it is written to a file that only root
 * can read, under /var/tmp (in RAM), and git is pointed at it with
 * "credential.helper=store --file=...". The file is removed when the run ends,
 * and a file left behind by a run that was killed is removed by the next one.
 * The token is never on a command line, in the environment, in the clone's
 * .git/config or in a log.
 *
 * The file holds the stack's own repository address, and git is told to match
 * the path as well as the host (credential.useHttpPath), so git offers the
 * token to that repository only, not to every repository on the same host.
 */
final class GitCredentials
{
    /** A file older than this, that no running git uses, was left by a run that died. */
    private const STALE_AFTER_SECONDS = 3600;

    /**
     * Write the credential for one repository to a new file, for one git run.
     *
     * @return string The file; pass it to settingsFor() and remove it with remove() afterwards
     * @throws RuntimeException if the credential is missing, is not a git credential, is for
     *                          another host, or the file cannot be written
     */
    public static function writeFile(string $credentialId, string $repositoryUrl): string
    {
        $repository = parse_url($repositoryUrl);
        if (!is_array($repository) || strtolower((string) ($repository['scheme'] ?? '')) !== 'https'
            || !isset($repository['host'], $repository['path'])) {
            throw new RuntimeException('A git credential can only be used with an https:// repository address.');
        }
        $host = strtolower($repository['host']) . (isset($repository['port']) ? ':' . $repository['port'] : '');

        $line = (new CredentialVault())->useCredential(
            $credentialId,
            static function (array $credential) use ($host, $repository): string {
                if (($credential['provider'] ?? '') !== 'git') {
                    throw new RuntimeException("The credential '{$credential['name']}' is not a git repository credential.");
                }
                if (($credential['registry'] ?? '') !== $host) {
                    throw new RuntimeException(
                        "The credential '{$credential['name']}' is for {$credential['registry']}, but the repository is on $host."
                    );
                }
                return 'https://' . rawurlencode($credential['username']) . ':' . rawurlencode($credential['secret'])
                    . '@' . $host . $repository['path'] . "\n";
            }
        );

        $directory = self::prepareDirectory();
        self::removeStaleFiles($directory);

        $file = $directory . '/' . bin2hex(random_bytes(16));
        // Created empty with root-only permissions first, so the token is never in a file others can read.
        $handle = @fopen($file, 'x');
        if ($handle === false) {
            throw new RuntimeException('Could not create a credential file for git.');
        }
        chmod($file, 0600);
        $written = fwrite($handle, $line);
        fclose($handle);
        if ($written !== strlen($line)) {
            @unlink($file);
            throw new RuntimeException('Could not write the credential file for git.');
        }
        return $file;
    }

    /**
     * The settings that make git use a credential file (for GitCommand::run's $extraConfig).
     *
     * @return string[]
     */
    public static function settingsFor(string $file): array
    {
        return [
            'credential.helper=store --file=' . $file,
            'credential.useHttpPath=true',
        ];
    }

    /**
     * Remove a credential file written by writeFile().
     */
    public static function remove(string $file): void
    {
        $directory = realpath(COMPOSE_GIT_CREDENTIAL_DIR);
        $target = realpath($file);
        if ($directory === false || $target === false || dirname($target) !== $directory) {
            return;
        }
        @unlink($target);
    }

    private static function prepareDirectory(): string
    {
        $directory = rtrim(COMPOSE_GIT_CREDENTIAL_DIR, '/');
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the folder for git credential files.');
        }
        chmod($directory, 0700);
        return $directory;
    }

    /**
     * Remove files left behind by runs that were killed before they could clean up.
     *
     * Every run that uses one of these files is time-limited to well under an
     * hour (GitCommand's and GitClone's timeouts), so a file older than that
     * belongs to no run that is still going.
     */
    private static function removeStaleFiles(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            $modified = @filemtime($file);
            if ($modified === false || time() - $modified <= self::STALE_AFTER_SECONDS) {
                continue;
            }
            self::remove($file);
        }
    }
}
