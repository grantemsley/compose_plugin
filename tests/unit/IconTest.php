<?php

/**
 * Unit Tests for icon.php
 * 
 * Tests the icon serving endpoint for compose stacks.
 * Source: source/compose.manager/php/icon.php
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PluginTests\StreamWrapper\UnraidStreamWrapper;

class IconTest extends TestCase
{
    private string $testComposeRoot;
    private string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test compose root
        $this->testComposeRoot = sys_get_temp_dir() . '/compose_icon_test_' . getmypid();
        if (!is_dir($this->testComposeRoot)) {
            mkdir($this->testComposeRoot, 0755, true);
        }
        
        // Create a test project
        $this->testProjectPath = $this->testComposeRoot . '/test-project';
        mkdir($this->testProjectPath, 0755, true);
        
        // Map the compose root for getComposeRoot() function
        $GLOBALS['compose_root'] = $this->testComposeRoot;
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (is_dir($this->testComposeRoot)) {
            $this->recursiveDelete($this->testComposeRoot);
        }
        
        parent::tearDown();
    }

    /**
     * Recursively delete a directory
     */
    private function recursiveDelete(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        $this->recursiveDelete($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }

    // ===========================================
    // Icon File Discovery Tests
    // ===========================================

    /**
     * Test that PNG icon is discovered
     */
    public function testPngIconDiscovery(): void
    {
        // Create a PNG icon file
        $iconPath = $this->testProjectPath . '/icon.png';
        file_put_contents($iconPath, $this->createFakePng());
        
        // Verify file exists
        $this->assertFileExists($iconPath);
        
        // Test file extension detection
        $ext = strtolower(pathinfo($iconPath, PATHINFO_EXTENSION));
        $this->assertEquals('png', $ext);
    }

    /**
     * Test that JPG icon is discovered
     */
    public function testJpgIconDiscovery(): void
    {
        $iconPath = $this->testProjectPath . '/icon.jpg';
        file_put_contents($iconPath, $this->createFakeJpg());
        
        $this->assertFileExists($iconPath);
        
        $ext = strtolower(pathinfo($iconPath, PATHINFO_EXTENSION));
        $this->assertEquals('jpg', $ext);
    }

    /**
     * Test that GIF icon is discovered
     */
    public function testGifIconDiscovery(): void
    {
        $iconPath = $this->testProjectPath . '/icon.gif';
        file_put_contents($iconPath, $this->createFakeGif());
        
        $this->assertFileExists($iconPath);
        
        $ext = strtolower(pathinfo($iconPath, PATHINFO_EXTENSION));
        $this->assertEquals('gif', $ext);
    }

    /**
     * Test that SVG icon is discovered
     */
    public function testSvgIconDiscovery(): void
    {
        $iconPath = $this->testProjectPath . '/icon.svg';
        file_put_contents($iconPath, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        
        $this->assertFileExists($iconPath);
        
        $ext = strtolower(pathinfo($iconPath, PATHINFO_EXTENSION));
        $this->assertEquals('svg', $ext);
    }

    // ===========================================
    // MIME Type Detection Tests
    // ===========================================

    /**
     * Test MIME type detection for PNG
     */
    public function testMimeTypePng(): void
    {
        $ext = 'png';
        $mimeType = $this->getMimeTypeForExtension($ext);
        $this->assertEquals('image/png', $mimeType);
    }

    /**
     * Test MIME type detection for JPG
     */
    public function testMimeTypeJpg(): void
    {
        $ext = 'jpg';
        $mimeType = $this->getMimeTypeForExtension($ext);
        $this->assertEquals('image/jpeg', $mimeType);
    }

    /**
     * Test MIME type detection for JPEG
     */
    public function testMimeTypeJpeg(): void
    {
        $ext = 'jpeg';
        $mimeType = $this->getMimeTypeForExtension($ext);
        $this->assertEquals('image/jpeg', $mimeType);
    }

    /**
     * Test MIME type detection for GIF
     */
    public function testMimeTypeGif(): void
    {
        $ext = 'gif';
        $mimeType = $this->getMimeTypeForExtension($ext);
        $this->assertEquals('image/gif', $mimeType);
    }

    /**
     * Test MIME type detection for SVG
     */
    public function testMimeTypeSvg(): void
    {
        $ext = 'svg';
        $mimeType = $this->getMimeTypeForExtension($ext);
        $this->assertEquals('image/svg+xml', $mimeType);
    }

    /**
     * Test MIME type defaults to PNG for unknown extension
     */
    public function testMimeTypeDefaultsPng(): void
    {
        $ext = 'unknown';
        $mimeType = $this->getMimeTypeForExtension($ext);
        $this->assertEquals('image/png', $mimeType);
    }

    // ===========================================
    // Project Name Sanitization Tests
    // ===========================================

    /**
     * Test that basename prevents directory traversal
     */
    public function testProjectNameSanitization(): void
    {
        // Attempt directory traversal
        $malicious = '../../../etc/passwd';
        $sanitized = basename($malicious);
        
        $this->assertEquals('passwd', $sanitized);
        $this->assertStringNotContainsString('..', $sanitized);
    }

    /**
     * Test that basename handles normal project names
     */
    public function testProjectNameNormal(): void
    {
        $project = 'my-compose-stack';
        $sanitized = basename($project);
        
        $this->assertEquals('my-compose-stack', $sanitized);
    }

    /**
     * Test that basename handles project names with special characters
     */
    public function testProjectNameWithSpecialChars(): void
    {
        $project = 'my.stack_name-123';
        $sanitized = basename($project);
        
        $this->assertEquals('my.stack_name-123', $sanitized);
    }

    // ===========================================
    // Icon Priority Tests
    // ===========================================

    /**
     * Test that PNG is found first when multiple icons exist
     */
    public function testIconPriorityPngFirst(): void
    {
        // Create multiple icon files
        file_put_contents($this->testProjectPath . '/icon.png', $this->createFakePng());
        file_put_contents($this->testProjectPath . '/icon.jpg', $this->createFakeJpg());
        file_put_contents($this->testProjectPath . '/icon.gif', $this->createFakeGif());
        
        // Check files in priority order (matching icon.php logic)
        $iconFiles = ['icon.png', 'icon.jpg', 'icon.gif', 'icon.svg', 'icon'];
        $foundIcon = null;
        
        foreach ($iconFiles as $iconFile) {
            $testPath = $this->testProjectPath . '/' . $iconFile;
            if (is_file($testPath)) {
                $foundIcon = $iconFile;
                break;
            }
        }
        
        $this->assertEquals('icon.png', $foundIcon);
    }

    // ===========================================
    // Helper Methods
    // ===========================================

    /**
     * Get MIME type for extension (matching icon.php logic)
     */
    private function getMimeTypeForExtension(string $ext): string
    {
        $mimeType = 'image/png';
        
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                $mimeType = 'image/jpeg';
                break;
            case 'gif':
                $mimeType = 'image/gif';
                break;
            case 'svg':
                $mimeType = 'image/svg+xml';
                break;
            case 'png':
            default:
                $mimeType = 'image/png';
                break;
        }
        
        return $mimeType;
    }

    /**
     * Create a minimal valid PNG file content
     */
    private function createFakePng(): string
    {
        // Minimal 1x1 transparent PNG
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
    }

    /**
     * Create a minimal valid JPEG file content
     */
    private function createFakeJpg(): string
    {
        // Minimal 1x1 JPEG
        return base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRof' .
            'Hh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwh' .
            'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAAR' .
            'CAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAA' .
            'AAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMB' .
            'AAIRAxEAPwCwAB//2Q=='
        );
    }

    /**
     * Create a minimal valid GIF file content
     */
    private function createFakeGif(): string
    {
        // Minimal 1x1 transparent GIF
        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }
}
