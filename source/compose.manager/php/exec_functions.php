<?php
/**
 * Exec Functions for Compose Manager
 * 
 * Contains utility functions used by exec.php for AJAX action handling.
 * Separated from exec.php to allow unit testing without triggering the switch statement.
 */

/**
 * Convert an element name to a safe HTML ID.
 * Replaces dots with dashes and removes spaces.
 *
 * @param string $element The element name to convert
 * @return string The sanitized ID
 */
function getElement($element) {
    $return = str_replace(".","-",$element);
    $return = str_replace(" ","",$return);
    return $return;
}

/**
 * Normalize Docker image name for update checking.
 * Strips the docker.io/ prefix (docker compose adds this for Docker Hub images)
 * and @sha256: digest suffix, then uses Unraid's DockerUtil::ensureImageTag
 * for consistent normalization matching how Unraid stores update status.
 *
 * @param string $image The image name to normalize
 * @return string The normalized image name
 */
function normalizeImageForUpdateCheck($image) {
    // Strip docker.io/ prefix (docker compose adds this for Docker Hub images)
    if (strpos($image, 'docker.io/') === 0) {
        $image = substr($image, 10); // Remove 'docker.io/'
    }
    // Strip @sha256: digest suffix if present (image pinning)
    if (($digestPos = strpos($image, '@sha256:')) !== false) {
        $image = substr($image, 0, $digestPos);
    }
    // Use Unraid's normalization for consistent key format (adds library/ prefix for official images, ensures tag)
    return DockerUtil::ensureImageTag($image);
}

/**
 * Sanitize a stack name to create a safe folder name.
 * Removes special characters that could cause issues in paths.
 *
 * @param string $stackName The stack name to sanitize
 * @return string The sanitized folder name
 */
function sanitizeFolderName($stackName) {
    $folderName = str_replace('"', "", $stackName);
    $folderName = str_replace("'", "", $folderName);
    $folderName = str_replace("&", "", $folderName);
    $folderName = str_replace("(", "", $folderName);
    $folderName = str_replace(")", "", $folderName);
    $folderName = preg_replace("/ {2,}/", " ", $folderName);
    $folderName = preg_replace("/\s/", "_", $folderName);
    return $folderName;
}

/**
 * Build the common compose CLI arguments for a stack.
 *
 * Resolves the project name, compose/override files, and env-file flag
 * from the stack directory.  Used by getStackContainers, checkStackUpdates,
 * and checkAllStacksUpdates to avoid duplicating this logic.
 *
 * @param string $stack  Stack directory name (basename under $compose_root)
 * @return array{projectName: string, files: string, envFile: string}
 */
function buildComposeArgs(string $stack): array {
    global $compose_root;

    $projectName = $stack;
    if (is_file("$compose_root/$stack/name")) {
        $projectName = trim(file_get_contents("$compose_root/$stack/name"));
    }
    $projectName = sanitizeStr($projectName);

    $basePath = getPath("$compose_root/$stack");
    $composeFile = findComposeFile($basePath) ?: "$basePath/docker-compose.yml";
    $overrideFile = "$compose_root/$stack/docker-compose.override.yml";

    $files = "-f " . escapeshellarg($composeFile);
    if (is_file($overrideFile)) {
        $files .= " -f " . escapeshellarg($overrideFile);
    }

    $envFile = "";
    if (is_file("$compose_root/$stack/envpath")) {
        $envPath = trim(file_get_contents("$compose_root/$stack/envpath"));
        if (is_file($envPath)) {
            $envFile = "--env-file " . escapeshellarg($envPath);
        }
    }

    return ['projectName' => $projectName, 'files' => $files, 'envFile' => $envFile];
}
