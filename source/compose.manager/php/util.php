<?php

require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/dynamix/include/Wrappers.php");

/**
 * Utility functions for Compose Manager
 *
 * This file contains shared utility functions used across the Compose Manager plugin.
 * Functions include logging, string sanitization, path handling, and stack operation locking.
 *
 * These functions are designed to be reusable and testable. They are used by both the
 * main plugin code and the AJAX action handlers.
 */

function clientDebug($message, $data = null, $type = 'daemon', $level = 'info')
{
    if ($type == '' || $type == null) {
        $type = 'daemon';
    }
    switch ($level) {
        case 'debug':
            $logLevel = "$type.debug";
            break;
        case 'error':
        case 'err':
            $logLevel = "$type.err";
            break;
        case 'warning':
        case 'warn':
            $logLevel = "$type.warning";
            break;
        case 'info':
        default:
            $logLevel = "$type.info";
    }
    $cfg = @parse_ini_file("/boot/config/plugins/compose.manager/compose.manager.cfg", true, INI_SCANNER_RAW);
    // Skip debug messages if debug logging is disabled in plugin settings
    if ((($cfg['DEBUG_TO_LOG'] ?? 'false') == 'false') && $level == 'debug') {
        return;
    }
    if ($data !== null && $data !== '' && $data !== 'null') {
        exec("logger -t 'compose.manager' -p '$logLevel' " . escapeshellarg($message) . ' - Data: ' . escapeshellarg($data));
    } else {
        exec("logger -t 'compose.manager' -p '$logLevel' " . escapeshellarg($message));
    }
}

function sanitizeStr($a)
{
    $a = str_replace(".", "_", $a);
    $a = str_replace(" ", "_", $a);
    $a = str_replace("-", "_", $a);
    return strtolower($a);
}

function isIndirect($path)
{
    return is_file("$path/indirect");
}

function getPath($basePath)
{
    $outPath = $basePath;
    if (isIndirect($basePath)) {
        $outPath = file_get_contents("$basePath/indirect");
    }

    return $outPath;
}

/**
 * Compose file names in priority order per the Docker Compose spec.
 * @see https://docs.docker.com/compose/intro/compose-application-model/#the-compose-file
 */
define('COMPOSE_FILE_NAMES', [
    'compose.yaml',
    'compose.yml',
    'docker-compose.yaml',
    'docker-compose.yml',
]);

/**
 * Find the compose file in a directory using Docker Compose spec priority.
 *
 * Checks for compose.yaml, compose.yml, docker-compose.yaml, docker-compose.yml
 * in that order and returns the first one found.
 *
 * @param string $dir The directory to search in
 * @return string|false The full path to the compose file, or false if none found
 */
function findComposeFile($dir)
{
    foreach (COMPOSE_FILE_NAMES as $name) {
        if (is_file("$dir/$name")) {
            return "$dir/$name";
        }
    }
    return false;
}

/**
 * Check whether a stack directory has a compose file (any of the supported names).
 *
 * @param string $dir The directory to check
 * @return bool
 */
function hasComposeFile($dir)
{
    return findComposeFile($dir) !== false;
}

class OverrideInfo
{
    /**
     * @var string Computed override filename (e.g. compose.override.yaml)
     */
    public string $computedName = '';
    /**
     * @var string|null Path to project override file
     */
    public ?string $projectOverride = null;
    /**
     * @var string|null Path to indirect override file
     */
    public ?string $indirectOverride = null;
    /**
     * @var bool True if indirect override should be used
     */
    public bool $useIndirect = false;
    /**
     * @var bool True if indirect contains legacy-named override but not correctly-named one
     */
    public bool $mismatchIndirectLegacy = false;

    /**
     * @var string Compose root directory
     */
    private string $composeRoot;

    /**
     * Constructor
     * @param string $composeRoot Compose root directory
     */
    private function __construct(string $composeRoot)
    {
        $this->composeRoot = rtrim($composeRoot, "/");
    }

    /**
     * Static factory to create and resolve an OverrideInfo for a stack.
     * @param string $composeRoot
     * @param string $stack
     * @return OverrideInfo
     */
    public static function fromStack(string $composeRoot, string $stack): OverrideInfo
    {
        $info = new self($composeRoot);
        $info->resolve($stack);
        return $info;
    }

    /**
     * Resolve override information for a given stack and populate this instance.
     * @param string $stack
     * @return void
     */
    private function resolve(string $stack): void
    {
        $projectPath = $this->getProjectPath($stack);
        $indirectPath = is_file("$projectPath/indirect") ? trim(file_get_contents("$projectPath/indirect")) : null;
        $composeSource = $indirectPath == "" || $indirectPath === null ? $projectPath : $indirectPath;

        $foundCompose = findComposeFile($composeSource);
        $composeBaseName = $foundCompose !== false ? basename($foundCompose) : COMPOSE_FILE_NAMES[0];
        $this->computedName = preg_replace('/(\.[^.]+)$/', '.override$1', $composeBaseName);

        $this->projectOverride = $projectPath . '/' . $this->computedName;
        $this->indirectOverride = $indirectPath !== "" && $indirectPath !== null ? ($indirectPath . '/' . $this->computedName) : null;

        $legacyProject = $projectPath . '/docker-compose.override.yml';
        $legacyIndirect = $indirectPath !== "" && $indirectPath !== null ? ($indirectPath . '/docker-compose.override.yml') : null;

        $this->useIndirect = ($this->indirectOverride && is_file($this->indirectOverride));
        $this->mismatchIndirectLegacy = ($indirectPath !== "" && $legacyIndirect && is_file($legacyIndirect) && !($this->indirectOverride && is_file($this->indirectOverride)));

        // Migrate legacy project override to computed project override (project-only migration)
        if (!is_file($this->projectOverride) && is_file($legacyProject) && realpath($legacyProject) !== @realpath($this->projectOverride)) {
            @rename($legacyProject, $this->projectOverride);
            clientDebug("[override] Migrated legacy project override $legacyProject -> $this->projectOverride", null, 'daemon', 'info');
        }

        if (is_file($this->projectOverride) && is_file($legacyProject) && realpath($legacyProject) !== @realpath($this->projectOverride)) {
            @rename($legacyProject, $legacyProject . ".bak");
            clientDebug("[override] Removed stale legacy project override $legacyProject (mismatch with computed override)", null, 'daemon', 'info');
        }

        if ($this->mismatchIndirectLegacy) {
            clientDebug("[override] Indirect override exists with non-matching name; using project fallback.", null, 'daemon', 'warning');
        }

        if (!is_file($this->projectOverride) && !$this->useIndirect) {
            $overrideContent = "# Override file for UI labels (icon, webui, shell)\n";
            $overrideContent .= "# This file is managed by Compose Manager\n";
            $overrideContent .= "services: {}\n";
            file_put_contents($this->projectOverride, $overrideContent);
            clientDebug("[override] Created missing project override template at $this->projectOverride", null, 'daemon', 'info');
        }
    }

    /**
     * Get the override file to use (indirect if present, else project override)
     * @return string|null
     */
    public function getOverridePath(): ?string
    {
        return $this->useIndirect ? $this->indirectOverride : $this->projectOverride;
    }

    /**
     * Get the project path for a stack
     * @param string $stack
     * @return string
     */
    private function getProjectPath(string $stack): string
    {
        return $this->composeRoot . '/' . $stack;
    }
}







/**
 * Stack operation locking functions
 * Prevents concurrent operations on the same stack
 */

// Lock directory override for testing - set via $GLOBALS['compose_lock_dir']

/**
 * Get the lock directory path
 * @return string
 */
function getLockDir(): string
{
    return $GLOBALS['compose_lock_dir'] ?? "/var/run/compose.manager";
}

/**
 * Acquire a lock for a stack operation
 * @param string $stackName The stack name/folder
 * @param int $timeout Maximum seconds to wait for lock (default 30)
 * @return resource|false File handle if lock acquired, false otherwise
 */
function acquireStackLock($stackName, $timeout = 30)
{
    $lockDir = getLockDir();
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0755, true);
    }

    $lockFile = "$lockDir/" . sanitizeStr($stackName) . ".lock";
    $fp = @fopen($lockFile, 'w');

    if (!$fp) {
        return false;
    }

    $waited = 0;
    while (!flock($fp, LOCK_EX | LOCK_NB)) {
        if ($waited >= $timeout) {
            fclose($fp);
            return false;
        }
        sleep(1);
        $waited++;
    }

    // Write lock info for debugging
    fwrite($fp, json_encode([
        'pid' => getmypid(),
        'time' => date('c'),
        'stack' => $stackName
    ]));
    fflush($fp);

    return $fp;
}

/**
 * Release a stack lock
 * @param resource $fp File handle from acquireStackLock
 */
function releaseStackLock($fp)
{
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Check if a stack is currently locked
 * @param string $stackName The stack name/folder
 * @return array|false Lock info if locked, false if not locked
 */
function isStackLocked($stackName)
{
    $lockDir = getLockDir();
    $lockFile = "$lockDir/" . sanitizeStr($stackName) . ".lock";

    if (!is_file($lockFile)) {
        return false;
    }

    $fp = @fopen($lockFile, 'r');
    if (!$fp) {
        return false;
    }

    // Try to get a non-blocking lock
    if (flock($fp, LOCK_EX | LOCK_NB)) {
        // Got the lock, so it wasn't locked
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    // Couldn't get lock, read the lock info
    $content = file_get_contents($lockFile);
    fclose($fp);

    $info = @json_decode($content, true);
    return $info ?: ['locked' => true];
}

/**
 * Get the last operation result for a stack
 * @param string $stackPath Full path to the stack directory
 * @return array|null Result info or null if not found
 */
function getStackLastResult($stackPath)
{
    $resultFile = "$stackPath/last_result.json";
    if (is_file($resultFile)) {
        $content = @file_get_contents($resultFile);
        if ($content) {
            return @json_decode($content, true);
        }
    }
    return null;
}

/**
 * Determine whether Compose-managed containers should be hidden from the Docker tab
 * Uses parse_plugin_cfg('compose.manager') when available (testable), or falls back to
 * parsing /boot/config/plugins/compose.manager/compose.manager.cfg
 *
 * @return bool
 */
function hide_compose_from_docker(): bool
{
    $cfg = [];
    if (function_exists('parse_plugin_cfg')) {
        $cfg = parse_plugin_cfg('compose.manager');
    } else {
        $cfg = @parse_ini_file('/boot/config/plugins/compose.manager/compose.manager.cfg') ?: [];
    }
    return (isset($cfg['HIDE_COMPOSE_FROM_DOCKER']) && $cfg['HIDE_COMPOSE_FROM_DOCKER'] === 'true');
}
