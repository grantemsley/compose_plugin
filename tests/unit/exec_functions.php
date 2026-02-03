<?php

/**
 * Extracted functions from exec.php for unit testing
 * 
 * These functions are extracted here to avoid the complex Unraid dependencies
 * in the full exec.php file. The logic is identical.
 */

// Only define if not already defined (allows testing in isolation)
if (!function_exists('getElement')) {
    function getElement($element) {
        $return = str_replace(".","-",$element);
        $return = str_replace(" ","",$return);
        return $return;
    }
}

// Mock DockerUtil if not available (Unraid-specific class)
if (!class_exists('DockerUtil')) {
    class DockerUtil {
        /**
         * Ensure an image has a tag, adding :latest if not present
         * Also adds library/ prefix for official Docker Hub images
         */
        public static function ensureImageTag($image) {
            // Handle official images (no slash in name = Docker Hub official)
            if (strpos($image, '/') === false) {
                $image = 'library/' . $image;
            }
            // Add :latest if no tag present
            if (strpos($image, ':') === false) {
                $image .= ':latest';
            }
            return $image;
        }
    }
}

if (!function_exists('normalizeImageForUpdateCheck')) {
    /**
     * Normalize Docker image name for update checking.
     * Strips the docker.io/ prefix (docker compose adds this for Docker Hub images)
     * and @sha256: digest suffix, then uses Unraid's DockerUtil::ensureImageTag
     * for consistent normalization matching how Unraid stores update status.
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
}
