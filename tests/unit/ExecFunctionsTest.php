<?php

/**
 * Unit Tests for exec.php functions
 * 
 * Tests utility functions defined in exec.php that can be tested in isolation.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PluginTests\Mocks\FunctionMocks;

// We need to define the function before including exec.php since it requires defines.php
// which has Unraid-specific dependencies. We'll extract the testable functions.

/**
 * @covers ::getElement
 * @covers ::normalizeImageForUpdateCheck
 */
class ExecFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Define the functions here since exec.php has complex dependencies
        if (!function_exists('getElement')) {
            require_once __DIR__ . '/exec_functions.php';
        }
    }

    // ===========================================
    // getElement() Tests
    // ===========================================

    /**
     * Test getElement replaces dots with dashes
     */
    public function testGetElementReplacesDots(): void
    {
        $result = getElement('my.stack.name');
        $this->assertEquals('my-stack-name', $result);
    }

    /**
     * Test getElement removes spaces
     */
    public function testGetElementRemovesSpaces(): void
    {
        $result = getElement('my stack name');
        $this->assertEquals('mystackname', $result);
    }

    /**
     * Test getElement handles combined cases
     */
    public function testGetElementCombined(): void
    {
        $result = getElement('My.Stack Name');
        $this->assertEquals('My-StackName', $result);
    }

    /**
     * Test getElement with empty string
     */
    public function testGetElementEmptyString(): void
    {
        $result = getElement('');
        $this->assertEquals('', $result);
    }

    /**
     * Test getElement preserves other characters
     */
    public function testGetElementPreservesOtherChars(): void
    {
        $result = getElement('stack-name_123');
        $this->assertEquals('stack-name_123', $result);
    }

    // ===========================================
    // normalizeImageForUpdateCheck() Tests  
    // ===========================================

    /**
     * Test normalizeImageForUpdateCheck strips docker.io prefix
     */
    public function testNormalizeImageStripsDockerIoPrefix(): void
    {
        $result = normalizeImageForUpdateCheck('docker.io/library/nginx:latest');
        $this->assertEquals('library/nginx:latest', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck strips sha256 digest
     */
    public function testNormalizeImageStripsSha256Digest(): void
    {
        $result = normalizeImageForUpdateCheck('nginx:latest@sha256:abc123def456');
        $this->assertEquals('library/nginx:latest', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck handles Docker Hub official images
     */
    public function testNormalizeImageHandlesOfficialImages(): void
    {
        // Official images should get library/ prefix added
        $result = normalizeImageForUpdateCheck('nginx');
        $this->assertEquals('library/nginx:latest', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck handles images without tag
     */
    public function testNormalizeImageAddsLatestTag(): void
    {
        $result = normalizeImageForUpdateCheck('myuser/myapp');
        $this->assertEquals('myuser/myapp:latest', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with docker.io and sha256
     */
    public function testNormalizeImageCombined(): void
    {
        $result = normalizeImageForUpdateCheck('docker.io/myuser/myapp:v1.0@sha256:abc123');
        $this->assertEquals('myuser/myapp:v1.0', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck preserves registry prefix for non-Docker Hub
     */
    public function testNormalizeImagePreservesCustomRegistry(): void
    {
        $result = normalizeImageForUpdateCheck('ghcr.io/owner/repo:tag');
        $this->assertEquals('ghcr.io/owner/repo:tag', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with full Docker Hub format
     */
    public function testNormalizeImageFullDockerHub(): void
    {
        $result = normalizeImageForUpdateCheck('docker.io/nginx');
        $this->assertEquals('library/nginx:latest', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with versioned tag
     */
    public function testNormalizeImageVersionedTag(): void
    {
        $result = normalizeImageForUpdateCheck('nginx:1.25.0');
        $this->assertEquals('library/nginx:1.25.0', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with user image and tag
     */
    public function testNormalizeImageUserWithTag(): void
    {
        $result = normalizeImageForUpdateCheck('linuxserver/plex:latest');
        $this->assertEquals('linuxserver/plex:latest', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with quay.io registry
     */
    public function testNormalizeImageQuayRegistry(): void
    {
        $result = normalizeImageForUpdateCheck('quay.io/prometheus/alertmanager:v0.25.0');
        $this->assertEquals('quay.io/prometheus/alertmanager:v0.25.0', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with digest only (no tag)
     */
    public function testNormalizeImageDigestOnly(): void
    {
        // docker.io/library/nginx@sha256:abc123... -> library/nginx:latest
        $result = normalizeImageForUpdateCheck('docker.io/library/nginx@sha256:abc123def456');
        $this->assertEquals('library/nginx:latest', $result);
    }
}
