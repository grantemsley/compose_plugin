<?php

function sanitizeStr($a) {
	$a = str_replace(".","_",$a);
	$a = str_replace(" ","_",$a);
	$a = str_replace("-","_",$a);
    return strtolower($a);
}

function isIndirect($path) {
    return is_file("$path/indirect");
}

function getPath($basePath) {
    $outPath = $basePath;
    if ( isIndirect($basePath) ) {
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
function findComposeFile($dir) {
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
function hasComposeFile($dir) {
    return findComposeFile($dir) !== false;
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
function getLockDir(): string {
    return $GLOBALS['compose_lock_dir'] ?? "/var/run/compose.manager";
}

/**
 * Acquire a lock for a stack operation
 * @param string $stackName The stack name/folder
 * @param int $timeout Maximum seconds to wait for lock (default 30)
 * @return resource|false File handle if lock acquired, false otherwise
 */
function acquireStackLock($stackName, $timeout = 30) {
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
function releaseStackLock($fp) {
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
function isStackLocked($stackName) {
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
function getStackLastResult($stackPath) {
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
function hide_compose_from_docker(): bool {
    $cfg = [];
    if (function_exists('parse_plugin_cfg')) {
        $cfg = parse_plugin_cfg('compose.manager');
    } else {
        $cfg = @parse_ini_file('/boot/config/plugins/compose.manager/compose.manager.cfg') ?: [];
    }
    return (isset($cfg['HIDE_COMPOSE_FROM_DOCKER']) && $cfg['HIDE_COMPOSE_FROM_DOCKER'] === 'true');
}

?>