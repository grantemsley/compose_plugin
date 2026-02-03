<?php

/**
 * Extracted functions from compose_util.php for unit testing
 * 
 * These functions are extracted here to avoid the complex Unraid dependencies
 * in the full compose_util.php file. The logic is identical.
 */

// Include util.php for sanitizeStr and path functions (already testable)
require_once __DIR__ . '/../../source/compose.manager/php/util.php';

if (!function_exists('buildComposeCommandArgs')) {
    /**
     * Build the argument array for a compose command
     * Extracted logic from echoComposeCommand() for testability
     * 
     * @param string $path Base path for the stack
     * @param string $action Compose action (up, down, pull, etc.)
     * @param string|null $profile Profile(s) to use (comma-separated)
     * @param bool $debug Whether to include debug flag
     * @return array<int, string> Array of command arguments
     */
    function buildComposeCommandArgs(string $path, string $action, ?string $profile, bool $debug): array
    {
        $composeCommand = [];
        
        // Get project name
        $projectName = basename($path);
        if (is_file("$path/name")) {
            $projectName = trim(file_get_contents("$path/name"));
        }
        $projectName = sanitizeStr($projectName);
        
        // Add action
        $composeCommand[] = "-c$action";
        
        // Add project name
        $composeCommand[] = "-p$projectName";
        
        // Add compose file
        if (isIndirect($path)) {
            $composeFile = getPath($path);
            $composeCommand[] = "-d$composeFile";
        } else {
            $composeFile = "$path/docker-compose.yml";
            $composeCommand[] = "-f$composeFile";
        }
        
        // Add override file if exists
        if (is_file("$path/docker-compose.override.yml")) {
            $composeCommand[] = "-f$path/docker-compose.override.yml";
        }
        
        // Add env path if exists
        if (is_file("$path/envpath")) {
            $envPath = trim(file_get_contents("$path/envpath"));
            $composeCommand[] = "-e$envPath";
        }
        
        // Add profiles (comma-separated to multiple -g flags)
        if ($profile) {
            $profileList = array_map('trim', explode(',', $profile));
            foreach ($profileList as $p) {
                if ($p) {
                    $composeCommand[] = "-g $p";
                }
            }
        }
        
        // Add stack path for timestamp saving
        $composeCommand[] = "-s$path";
        
        // Add debug flag if needed
        if ($debug) {
            $composeCommand[] = '--debug';
        }
        
        return $composeCommand;
    }
}

if (!function_exists('formatComposeCommandForOutput')) {
    /**
     * Format compose command array for URL output (non-ttyd style)
     * 
     * @param array<int, string> $args Command arguments
     * @return string Formatted command string for URL params
     */
    function formatComposeCommandForOutput(array $args): string
    {
        $i = 0;
        return array_reduce($args, function($v1, $v2) use (&$i) {
            if ($v2[0] == "-") {
                $i++;
                return $v1 . "&arg" . $i . "=" . $v2;
            } else {
                return $v1 . $v2;
            }
        }, "");
    }
}

if (!function_exists('sanitizeStackName')) {
    /**
     * Sanitize a stack name for use in file operations
     * Removes problematic characters that could cause shell or filesystem issues
     * 
     * @param string $name Raw stack name input
     * @return string Sanitized name safe for use as folder name
     */
    function sanitizeStackName(string $name): string
    {
        $name = str_replace('"', "", $name);
        $name = str_replace("'", "", $name);
        $name = str_replace("&", "", $name);
        $name = str_replace("(", "", $name);
        $name = str_replace(")", "", $name);
        $name = preg_replace("/ {2,}/", " ", $name);
        $name = preg_replace("/\s/", "_", $name);
        return $name;
    }
}
