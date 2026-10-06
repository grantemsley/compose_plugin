<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackManager.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitSsh.php';

/**
 * What the web UI asks of git stacks. Each method returns the array that
 * Exec.php sends back as JSON: 'result' is 'success' or 'error', and an error
 * has a 'message' to show. The work itself is done by GitStackManager, the
 * same code the compose-git command uses.
 */
final class GitStackWebActions
{
    public function __construct(private readonly string $composeRoot)
    {
    }

    /**
     * Create a git stack from the Add Stack dialog: clone the repository and
     * make the stack folder, as compose-git add does. Nothing is deployed.
     *
     * @param array<string, mixed> $input The dialog's fields: stackName, stackDesc,
     *     gitUrl, gitBranch, gitComposePath, gitCredentialId, overrideManagementAutomatic
     * @return array<string, mixed>
     */
    public function add(array $input): array
    {
        $messages = [];
        $manager = new GitStackManager($this->composeRoot, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $branch = trim((string) ($input['gitBranch'] ?? ''));
        $credentialId = trim((string) ($input['gitCredentialId'] ?? ''));
        try {
            $folder = $manager->add(
                trim((string) ($input['stackName'] ?? '')),
                trim((string) ($input['gitUrl'] ?? '')),
                $branch === '' ? GitStackManager::DEFAULT_BRANCH : $branch,
                trim((string) ($input['gitComposePath'] ?? '')),
                null,
                trim((string) ($input['stackDesc'] ?? '')),
                // Only a git credential is accepted, never a registry login.
                $credentialId === '' ? null : $manager->findGitCredential($credentialId)
            );
        } catch (Throwable $error) {
            return self::errorAnswer($error, $messages);
        }

        // The same override choice as the dialog's other sources (see addStack in Exec.php).
        $overrideManagementAutomatic = strtolower(trim((string) ($input['overrideManagementAutomatic'] ?? 'true'))) !== 'false';
        if (!$overrideManagementAutomatic) {
            $labelsViewModeFile = $this->composeRoot . '/' . $folder . '/labels_view_mode';
            if (@file_put_contents($labelsViewModeFile, 'advanced') === false) {
                // The stack is made; only its Labels tab opens in the basic view, where it can be switched.
                composeLogger("Added git stack '$folder', but could not write $labelsViewModeFile", null, 'user', 'warning', 'git');
            }
        }

        StackInfo::clearCache();
        $stack = StackInfo::fromProject($this->composeRoot, $folder);
        return [
            'result' => 'success',
            'project' => $folder,
            'projectName' => $stack->getName(),
            'messages' => $messages,
        ];
    }

    /**
     * A git stack's repository, deployed commit and local changes, for the
     * editor's Sources tab. Asks nothing of the remote.
     *
     * @return array<string, mixed>
     */
    public function status(string $folder): array
    {
        $manager = new GitStackManager($this->composeRoot);
        try {
            $status = $manager->status($folder);
        } catch (Throwable $error) {
            return ['result' => 'error', 'message' => $error->getMessage()];
        }

        // An ssh stack's deploy key, so it can be added to the repository from here.
        $deployKey = null;
        try {
            $deployKey = $manager->deployKey($folder);
        } catch (Throwable $error) {
            // Not an ssh stack: there is nothing to show. An ssh stack whose key cannot be read says why.
            if (GitStackSettings::isSshUrl((string) $status['url']) && $status['problem'] === null) {
                // GitSsh already starts its message this way when ssh-keygen refuses the key.
                $message = $error->getMessage();
                if (!str_starts_with($message, 'Could not read the deploy key')) {
                    $message = 'Could not read the deploy key: ' . $message;
                }
                $status['problem'] = $message;
            }
        }

        return ['result' => 'success', 'git' => $status + ['deployKey' => $deployKey]];
    }

    /**
     * The answer for a failed add or convert. When the repository did not know an ssh
     * stack's deploy key, the key comes apart from the clone's error, so the dialog can
     * show it as the next step rather than inside the error.
     *
     * @param list<string> $messages What the manager said before it failed
     * @return array<string, mixed>
     */
    private static function errorAnswer(Throwable $error, array $messages): array
    {
        if ($error instanceof GitDeployKeyNotAddedException) {
            return [
                'result' => 'error',
                'message' => $error->cloneError,
                'deployKey' => $error->publicKey,
                'messages' => $messages,
            ];
        }
        return ['result' => 'error', 'message' => $error->getMessage(), 'messages' => $messages];
    }
}
