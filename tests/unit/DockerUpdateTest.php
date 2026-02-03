<?php

/**
 * Unit Tests for Docker Update Checking
 * 
 * Tests the normalizeImageForUpdateCheck function and DockerUpdate integration.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PluginTests\Mocks\DockerUtilMock;

// Load the testable functions
require_once __DIR__ . '/exec_functions.php';

class DockerUpdateTest extends TestCase
{
    // ===========================================
    // Image Normalization for Update Checking
    // ===========================================

    /**
     * Test normalizeImageForUpdateCheck with various Docker Hub formats
     */
    public function testNormalizeDockerHubFormats(): void
    {
        // docker.io prefix is stripped
        $this->assertEquals(
            'library/nginx:latest',
            normalizeImageForUpdateCheck('docker.io/nginx')
        );
        
        // docker.io/library prefix is stripped to just library
        $this->assertEquals(
            'library/nginx:latest',
            normalizeImageForUpdateCheck('docker.io/library/nginx')
        );
        
        // User images from docker.io
        $this->assertEquals(
            'linuxserver/plex:latest',
            normalizeImageForUpdateCheck('docker.io/linuxserver/plex')
        );
    }

    /**
     * Test sha256 digest stripping
     */
    public function testStripsSha256Digest(): void
    {
        // Image with both tag and digest
        $this->assertEquals(
            'library/nginx:1.25',
            normalizeImageForUpdateCheck('nginx:1.25@sha256:abc123def456')
        );
        
        // Image with only digest (no tag)
        $this->assertEquals(
            'library/redis:latest',
            normalizeImageForUpdateCheck('redis@sha256:abc123')
        );
        
        // Full docker.io path with digest
        $this->assertEquals(
            'myuser/myapp:v2.0',
            normalizeImageForUpdateCheck('docker.io/myuser/myapp:v2.0@sha256:xyz789')
        );
    }

    // ===========================================
    // DockerUpdate Mock Integration
    // ===========================================

    /**
     * Test image is up to date when local and remote SHA match
     */
    public function testImageUpToDate(): void
    {
        $image = 'library/nginx:latest';
        $sha = 'sha256:abc123def456';
        
        $this->mockUpdateStatus($image, $sha, $sha);
        
        $update = new \DockerUpdate();
        $update->reloadUpdateStatus($image);
        
        $this->assertTrue($update->getUpdateStatus($image));
    }

    /**
     * Test image has update available when SHA differs
     */
    public function testImageHasUpdate(): void
    {
        $image = 'library/redis:7';
        
        $this->mockUpdateStatus($image, 'sha256:old111', 'sha256:new222');
        
        $update = new \DockerUpdate();
        $update->reloadUpdateStatus($image);
        
        $this->assertFalse($update->getUpdateStatus($image));
    }

    /**
     * Test unknown image returns null status
     */
    public function testUnknownImageStatus(): void
    {
        $update = new \DockerUpdate();
        $update->reloadUpdateStatus('nonexistent/image:tag');
        
        $this->assertNull($update->getUpdateStatus('nonexistent/image:tag'));
    }

    /**
     * Test image with null local SHA returns unknown
     */
    public function testMissingLocalSha(): void
    {
        $image = 'library/mysql:8';
        
        $this->mockUpdateStatus($image, null, 'sha256:remote123');
        
        $update = new \DockerUpdate();
        $update->reloadUpdateStatus($image);
        
        $this->assertNull($update->getUpdateStatus($image));
    }

    // ===========================================
    // DockerClient Mock Integration
    // ===========================================

    /**
     * Test DockerClient returns mocked containers
     */
    public function testDockerClientGetContainers(): void
    {
        $this->mockContainers([
            'compose_nginx_1' => [
                'Name' => 'compose_nginx_1',
                'Image' => 'nginx:latest',
                'State' => 'running',
                'Labels' => 'com.docker.compose.project=mystack',
            ],
            'compose_redis_1' => [
                'Name' => 'compose_redis_1', 
                'Image' => 'redis:7',
                'State' => 'running',
                'Labels' => 'com.docker.compose.project=mystack',
            ],
        ]);
        
        $client = new \DockerClient();
        $containers = $client->getDockerContainers();
        
        $this->assertCount(2, $containers);
        $this->assertEquals('compose_nginx_1', $containers[0]['Name']);
        $this->assertEquals('running', $containers[0]['State']);
    }

    /**
     * Test filtering running containers
     */
    public function testGetRunningContainers(): void
    {
        $this->mockContainers([
            'running_container' => [
                'Name' => 'running_container',
                'Image' => 'nginx:latest',
                'State' => 'running',
            ],
            'stopped_container' => [
                'Name' => 'stopped_container',
                'Image' => 'redis:latest',
                'State' => 'exited',
            ],
        ]);
        
        $running = \DockerUtil::getRunningContainers();
        
        $this->assertCount(1, $running);
        $this->assertArrayHasKey('running_container', $running);
        $this->assertArrayNotHasKey('stopped_container', $running);
    }

    // ===========================================
    // DockerUtil JSON Methods
    // ===========================================

    /**
     * Test DockerUtil loadJSON and saveJSON
     */
    public function testDockerUtilJsonMethods(): void
    {
        $path = '/var/lib/docker/unraid-update-status.json';
        $data = [
            'library/nginx:latest' => [
                'local' => 'sha256:abc',
                'remote' => 'sha256:abc',
            ],
            'library/redis:7' => [
                'local' => 'sha256:old',
                'remote' => 'sha256:new',
            ],
        ];
        
        // Save JSON
        \DockerUtil::saveJSON($path, $data);
        
        // Load it back
        $loaded = \DockerUtil::loadJSON($path);
        
        $this->assertEquals($data, $loaded);
        $this->assertArrayHasKey('library/nginx:latest', $loaded);
    }

    /**
     * Test loading non-existent JSON file returns empty array
     */
    public function testLoadNonExistentJson(): void
    {
        $result = \DockerUtil::loadJSON('/nonexistent/path.json');
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
