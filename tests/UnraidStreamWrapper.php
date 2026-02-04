<?php

/**
 * Stream Wrapper to intercept Unraid file paths
 * 
 * This allows us to redirect requires from /usr/local/emhttp/... to our mock files
 * so we can test the actual plugin source code.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

class UnraidStreamWrapper
{
    /** @var resource|null */
    public $context;
    
    /** @var resource|null */
    private $handle = null;
    
    /** @var array<string, string> Path mappings from Unraid paths to local paths */
    private static array $pathMappings = [];
    
    /** @var array<string, string> Files that should return mock content */
    private static array $mockContent = [];
    
    /** @var bool Whether wrapper is registered */
    private static bool $registered = false;
    
    /**
     * Register the stream wrapper
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        
        // Unregister the built-in file:// wrapper
        stream_wrapper_unregister('file');
        
        // Register our wrapper
        stream_wrapper_register('file', self::class);
        
        self::$registered = true;
    }
    
    /**
     * Unregister the stream wrapper and restore default
     */
    public static function unregister(): void
    {
        if (!self::$registered) {
            return;
        }
        
        stream_wrapper_unregister('file');
        stream_wrapper_restore('file');
        
        self::$registered = false;
    }
    
    /**
     * Add a path mapping
     * 
     * @param string $unraidPath The Unraid path (e.g., /usr/local/emhttp/plugins/dynamix/include/Wrappers.php)
     * @param string $localPath The local path to use instead
     */
    public static function addMapping(string $unraidPath, string $localPath): void
    {
        self::$pathMappings[$unraidPath] = $localPath;
    }
    
    /**
     * Add mock content for a file
     * 
     * @param string $path The path that should return this content
     * @param string $content The PHP content to return
     */
    public static function addMockContent(string $path, string $content): void
    {
        self::$mockContent[$path] = $content;
    }
    
    /**
     * Clear all mappings
     */
    public static function clearMappings(): void
    {
        self::$pathMappings = [];
        self::$mockContent = [];
    }
    
    /**
     * Resolve a path through our mappings
     * Only redirects Unraid paths, passes through everything else
     */
    private function resolvePath(string $path): string
    {
        // Normalize path separators for comparison
        $normalizedPath = str_replace('\\', '/', $path);
        
        // Check for exact mapping
        if (isset(self::$pathMappings[$normalizedPath])) {
            return self::$pathMappings[$normalizedPath];
        }
        
        // Check for mock content
        if (isset(self::$mockContent[$normalizedPath])) {
            $tempFile = sys_get_temp_dir() . '/unraid_mock_' . md5($normalizedPath) . '.php';
            // Temporarily unregister to write file
            self::unregister();
            file_put_contents($tempFile, self::$mockContent[$normalizedPath]);
            self::register();
            return $tempFile;
        }
        
        // Pass through non-Unraid paths unchanged
        return $path;
    }
    
    /**
     * Check if a path is an Unraid path we should intercept
     */
    private static function isUnraidPath(string $path): bool
    {
        $normalizedPath = str_replace('\\', '/', $path);
        
        // Check if it's a mapped path
        if (isset(self::$pathMappings[$normalizedPath])) {
            return true;
        }
        
        // Check if it's a mock content path
        if (isset(self::$mockContent[$normalizedPath])) {
            return true;
        }
        
        // Check for Unraid-style paths
        return strpos($normalizedPath, '/usr/local/emhttp/') === 0 
            || strpos($normalizedPath, '/var/local/emhttp/') === 0
            || strpos($normalizedPath, '/boot/config/plugins/') === 0;
    }
    
    /**
     * Open a file
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        // Strip file:// prefix if present
        $path = preg_replace('#^file://#', '', $path);
        
        $resolvedPath = $this->resolvePath($path);
        
        // Temporarily unregister to use real file operations
        self::unregister();
        
        $this->handle = @fopen($resolvedPath, $mode);
        
        // Re-register
        self::register();
        
        if ($this->handle !== false) {
            $opened_path = $resolvedPath;
            return true;
        }
        
        return false;
    }
    
    /**
     * Read from file
     */
    public function stream_read(int $count): string|false
    {
        if ($this->handle === null) {
            return false;
        }
        return fread($this->handle, $count);
    }
    
    /**
     * Write to file
     */
    public function stream_write(string $data): int
    {
        if ($this->handle === null) {
            return 0;
        }
        $result = fwrite($this->handle, $data);
        return $result === false ? 0 : $result;
    }
    
    /**
     * Lock/unlock file
     */
    public function stream_lock(int $operation): bool
    {
        if ($this->handle === null) {
            return false;
        }
        // Handle the case where $operation is 0 (which is invalid for flock)
        if ($operation === 0) {
            return true; // Just pretend it succeeded
        }
        return flock($this->handle, $operation);
    }
    
    /**
     * Check for end of file
     */
    public function stream_eof(): bool
    {
        if ($this->handle === null) {
            return true;
        }
        return feof($this->handle);
    }
    
    /**
     * Close the file
     */
    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }
    
    /**
     * Get file stats
     */
    public function stream_stat(): array|false
    {
        if ($this->handle === null) {
            return false;
        }
        return fstat($this->handle);
    }
    
    /**
     * Seek in file
     */
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($this->handle === null) {
            return false;
        }
        return fseek($this->handle, $offset, $whence) === 0;
    }
    
    /**
     * Get current position
     */
    public function stream_tell(): int|false
    {
        if ($this->handle === null) {
            return false;
        }
        return ftell($this->handle);
    }
    
    /**
     * Flush output
     */
    public function stream_flush(): bool
    {
        if ($this->handle === null) {
            return false;
        }
        return fflush($this->handle);
    }
    
    /**
     * URL stat (for file_exists, is_file, etc.)
     */
    public function url_stat(string $path, int $flags): array|false
    {
        // Strip file:// prefix if present
        $path = preg_replace('#^file://#', '', $path);
        
        $resolvedPath = $this->resolvePath($path);
        
        // Temporarily unregister
        self::unregister();
        
        if ($flags & STREAM_URL_STAT_QUIET) {
            $stat = @stat($resolvedPath);
        } else {
            $stat = stat($resolvedPath);
        }
        
        // Re-register
        self::register();
        
        return $stat !== false ? $stat : false;
    }
    
    /**
     * Unlink (delete) a file
     */
    public function unlink(string $path): bool
    {
        $path = preg_replace('#^file://#', '', $path);
        $resolvedPath = $this->resolvePath($path);
        
        self::unregister();
        $result = @unlink($resolvedPath);
        self::register();
        
        return $result;
    }
    
    /**
     * Rename a file
     */
    public function rename(string $from, string $to): bool
    {
        $from = preg_replace('#^file://#', '', $from);
        $to = preg_replace('#^file://#', '', $to);
        
        $resolvedFrom = $this->resolvePath($from);
        $resolvedTo = $this->resolvePath($to);
        
        self::unregister();
        $result = @rename($resolvedFrom, $resolvedTo);
        self::register();
        
        return $result;
    }
    
    /**
     * Create a directory
     */
    public function mkdir(string $path, int $mode, int $options): bool
    {
        $path = preg_replace('#^file://#', '', $path);
        $resolvedPath = $this->resolvePath($path);
        
        self::unregister();
        $result = @mkdir($resolvedPath, $mode, (bool)($options & STREAM_MKDIR_RECURSIVE));
        self::register();
        
        return $result;
    }
    
    /**
     * Remove a directory
     */
    public function rmdir(string $path, int $options): bool
    {
        $path = preg_replace('#^file://#', '', $path);
        $resolvedPath = $this->resolvePath($path);
        
        self::unregister();
        $result = @rmdir($resolvedPath);
        self::register();
        
        return $result;
    }
    
    /**
     * Open a directory
     * @var resource|false
     */
    private $dirHandle = false;
    
    public function dir_opendir(string $path, int $options): bool
    {
        $path = preg_replace('#^file://#', '', $path);
        $resolvedPath = $this->resolvePath($path);
        
        self::unregister();
        $this->dirHandle = @opendir($resolvedPath);
        self::register();
        
        return $this->dirHandle !== false;
    }
    
    public function dir_readdir(): string|false
    {
        if ($this->dirHandle === false) {
            return false;
        }
        return readdir($this->dirHandle);
    }
    
    public function dir_rewinddir(): bool
    {
        if ($this->dirHandle === false) {
            return false;
        }
        rewinddir($this->dirHandle);
        return true;
    }
    
    public function dir_closedir(): bool
    {
        if ($this->dirHandle === false) {
            return false;
        }
        closedir($this->dirHandle);
        $this->dirHandle = false;
        return true;
    }
    
    /**
     * Set metadata (touch, chmod, chown, chgrp)
     */
    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        $path = preg_replace('#^file://#', '', $path);
        $resolvedPath = $this->resolvePath($path);
        
        self::unregister();
        
        $result = match ($option) {
            STREAM_META_TOUCH => empty($value) ? @touch($resolvedPath) : @touch($resolvedPath, $value[0], $value[1] ?? $value[0]),
            STREAM_META_OWNER_NAME, STREAM_META_OWNER => @chown($resolvedPath, $value),
            STREAM_META_GROUP_NAME, STREAM_META_GROUP => @chgrp($resolvedPath, $value),
            STREAM_META_ACCESS => @chmod($resolvedPath, $value),
            default => false,
        };
        
        self::register();
        
        return $result;
    }
    
    /**
     * Truncate stream
     */
    public function stream_truncate(int $new_size): bool
    {
        if ($this->handle === null) {
            return false;
        }
        return ftruncate($this->handle, $new_size);
    }
    
    /**
     * Set stream options
     */
    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false; // Not implemented
    }
}
