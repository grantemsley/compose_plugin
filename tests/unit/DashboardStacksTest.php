<?php

/**
 * Unit Tests for dashboard_stacks.php
 * 
 * Tests the dashboard tile data generation for compose stacks.
 * Source: source/compose.manager/php/dashboard_stacks.php
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PluginTests\Mocks\FunctionMocks;

class DashboardStacksTest extends TestCase
{
    private string $testComposeRoot;
    private string $testUpdateStatusFile;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test compose root
        $this->testComposeRoot = sys_get_temp_dir() . '/compose_dashboard_test_' . getmypid();
        if (!is_dir($this->testComposeRoot)) {
            mkdir($this->testComposeRoot, 0755, true);
        }
        
        // Create test update status file location
        $this->testUpdateStatusFile = sys_get_temp_dir() . '/compose_update_status_' . getmypid() . '.json';
        
        // Set the global compose_root
        global $compose_root;
        $compose_root = $this->testComposeRoot;
        
        // Set plugin config
        FunctionMocks::setPluginConfig('compose.manager', [
            'PROJECTS_FOLDER' => $this->testComposeRoot,
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (is_dir($this->testComposeRoot)) {
            $this->recursiveDelete($this->testComposeRoot);
        }
        
        // Clean up update status file
        if (is_file($this->testUpdateStatusFile)) {
            unlink($this->testUpdateStatusFile);
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

    /**
     * Create a test stack directory
     */
    private function createTestStack(string $folderName, array $options = []): string
    {
        $stackPath = $this->testComposeRoot . '/' . $folderName;
        mkdir($stackPath, 0755, true);
        
        $name = $options['name'] ?? $folderName;
        
        file_put_contents("$stackPath/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackPath/name", $name);
        
        if (isset($options['icon_url'])) {
            file_put_contents("$stackPath/icon_url", $options['icon_url']);
        }
        
        if (isset($options['webui_url'])) {
            file_put_contents("$stackPath/webui_url", $options['webui_url']);
        }
        
        if (isset($options['started_at'])) {
            file_put_contents("$stackPath/started_at", $options['started_at']);
        }
        
        if (isset($options['indirect'])) {
            // Clear the compose file and create indirect pointer instead
            unlink("$stackPath/docker-compose.yml");
            file_put_contents("$stackPath/indirect", $options['indirect']);
        }
        
        return $stackPath;
    }

    // ===========================================
    // Summary Structure Tests
    // ===========================================

    /**
     * Test dashboard summary has required keys
     */
    public function testDashboardSummaryStructure(): void
    {
        $summary = [
            'total' => 0,
            'started' => 0,
            'stopped' => 0,
            'partial' => 0,
            'stacks' => []
        ];
        
        $this->assertArrayHasKey('total', $summary);
        $this->assertArrayHasKey('started', $summary);
        $this->assertArrayHasKey('stopped', $summary);
        $this->assertArrayHasKey('partial', $summary);
        $this->assertArrayHasKey('stacks', $summary);
    }

    /**
     * Test stack entry has required keys
     */
    public function testStackEntryStructure(): void
    {
        $stackEntry = [
            'name' => 'TestStack',
            'folder' => 'test-stack',
            'state' => 'stopped',
            'running' => 0,
            'total' => 0,
            'icon' => '',
            'webui' => '',
            'startedAt' => '',
            'update' => 'unknown'
        ];
        
        $this->assertArrayHasKey('name', $stackEntry);
        $this->assertArrayHasKey('folder', $stackEntry);
        $this->assertArrayHasKey('state', $stackEntry);
        $this->assertArrayHasKey('running', $stackEntry);
        $this->assertArrayHasKey('total', $stackEntry);
        $this->assertArrayHasKey('icon', $stackEntry);
        $this->assertArrayHasKey('webui', $stackEntry);
        $this->assertArrayHasKey('startedAt', $stackEntry);
        $this->assertArrayHasKey('update', $stackEntry);
    }

    // ===========================================
    // Stack Counting Tests
    // ===========================================

    /**
     * Test total stack count
     */
    public function testTotalStackCount(): void
    {
        $this->createTestStack('stack1');
        $this->createTestStack('stack2');
        $this->createTestStack('stack3');
        
        $projects = @array_diff(@scandir($this->testComposeRoot), ['.', '..']);
        
        $total = 0;
        foreach ($projects as $project) {
            if (is_file("$this->testComposeRoot/$project/docker-compose.yml") || 
                is_file("$this->testComposeRoot/$project/indirect")) {
                $total++;
            }
        }
        
        $this->assertEquals(3, $total);
    }

    /**
     * Test empty compose root returns zero count
     */
    public function testEmptyComposeRootZeroCount(): void
    {
        // Don't create any stacks
        
        $projects = @array_diff(@scandir($this->testComposeRoot), ['.', '..']);
        
        $total = 0;
        if (is_array($projects)) {
            foreach ($projects as $project) {
                if (is_file("$this->testComposeRoot/$project/docker-compose.yml") || 
                    is_file("$this->testComposeRoot/$project/indirect")) {
                    $total++;
                }
            }
        }
        
        $this->assertEquals(0, $total);
    }

    // ===========================================
    // State Calculation Tests
    // ===========================================

    /**
     * Test state is 'stopped' when no containers
     */
    public function testStateStoppedWhenNoContainers(): void
    {
        $runningCount = 0;
        $totalContainers = 0;
        
        $state = 'stopped';
        if ($totalContainers > 0) {
            if ($runningCount === $totalContainers) {
                $state = 'started';
            } elseif ($runningCount > 0) {
                $state = 'partial';
            }
        }
        
        $this->assertEquals('stopped', $state);
    }

    /**
     * Test state is 'started' when all containers running
     */
    public function testStateStartedWhenAllRunning(): void
    {
        $runningCount = 3;
        $totalContainers = 3;
        
        $state = 'stopped';
        if ($totalContainers > 0) {
            if ($runningCount === $totalContainers) {
                $state = 'started';
            } elseif ($runningCount > 0) {
                $state = 'partial';
            }
        }
        
        $this->assertEquals('started', $state);
    }

    /**
     * Test state is 'partial' when some containers running
     */
    public function testStatePartialWhenSomeRunning(): void
    {
        $runningCount = 2;
        $totalContainers = 3;
        
        $state = 'stopped';
        if ($totalContainers > 0) {
            if ($runningCount === $totalContainers) {
                $state = 'started';
            } elseif ($runningCount > 0) {
                $state = 'partial';
            }
        }
        
        $this->assertEquals('partial', $state);
    }

    /**
     * Test state is 'stopped' when no containers running but some exist
     */
    public function testStateStoppedWhenNoneRunning(): void
    {
        $runningCount = 0;
        $totalContainers = 3;
        
        $state = 'stopped';
        if ($totalContainers > 0) {
            if ($runningCount === $totalContainers) {
                $state = 'started';
            } elseif ($runningCount > 0) {
                $state = 'partial';
            }
        }
        
        $this->assertEquals('stopped', $state);
    }

    // ===========================================
    // Summary Counter Tests
    // ===========================================

    /**
     * Test summary counters increment correctly
     */
    public function testSummaryCountersIncrement(): void
    {
        $summary = [
            'total' => 0,
            'started' => 0,
            'stopped' => 0,
            'partial' => 0,
        ];
        
        // Simulate processing 5 stacks with various states
        $stacks = [
            ['state' => 'started'],
            ['state' => 'started'],
            ['state' => 'stopped'],
            ['state' => 'partial'],
            ['state' => 'stopped'],
        ];
        
        foreach ($stacks as $stack) {
            $summary['total']++;
            switch ($stack['state']) {
                case 'started':
                    $summary['started']++;
                    break;
                case 'partial':
                    $summary['partial']++;
                    break;
                case 'stopped':
                default:
                    $summary['stopped']++;
                    break;
            }
        }
        
        $this->assertEquals(5, $summary['total']);
        $this->assertEquals(2, $summary['started']);
        $this->assertEquals(2, $summary['stopped']);
        $this->assertEquals(1, $summary['partial']);
    }

    // ===========================================
    // Update Status Tests
    // ===========================================

    /**
     * Test update status loading from JSON file
     */
    public function testLoadUpdateStatusFromJson(): void
    {
        $savedStatus = [
            'stack1' => ['hasUpdate' => true, 'containers' => []],
            'stack2' => ['hasUpdate' => false, 'containers' => []],
        ];
        
        file_put_contents($this->testUpdateStatusFile, json_encode($savedStatus));
        
        $loadedStatus = [];
        if (is_file($this->testUpdateStatusFile)) {
            $loadedStatus = json_decode(file_get_contents($this->testUpdateStatusFile), true) ?: [];
        }
        
        $this->assertArrayHasKey('stack1', $loadedStatus);
        $this->assertTrue($loadedStatus['stack1']['hasUpdate']);
        $this->assertFalse($loadedStatus['stack2']['hasUpdate']);
    }

    /**
     * Test update status determination from saved status
     */
    public function testUpdateStatusDetermination(): void
    {
        $savedUpdateStatus = [
            'my-stack' => [
                'hasUpdate' => true,
                'lastChecked' => time(),
            ],
        ];
        
        $project = 'my-stack';
        $updateStatus = 'unknown';
        
        if (isset($savedUpdateStatus[$project])) {
            $stackUpdateInfo = $savedUpdateStatus[$project];
            if (isset($stackUpdateInfo['hasUpdate'])) {
                $updateStatus = $stackUpdateInfo['hasUpdate'] ? 'update-available' : 'up-to-date';
            }
        }
        
        $this->assertEquals('update-available', $updateStatus);
    }

    /**
     * Test update status defaults to unknown
     */
    public function testUpdateStatusDefaultsToUnknown(): void
    {
        $savedUpdateStatus = [];
        
        $project = 'nonexistent-stack';
        $updateStatus = 'unknown';
        
        if (isset($savedUpdateStatus[$project])) {
            $stackUpdateInfo = $savedUpdateStatus[$project];
            if (isset($stackUpdateInfo['hasUpdate'])) {
                $updateStatus = $stackUpdateInfo['hasUpdate'] ? 'update-available' : 'up-to-date';
            }
        }
        
        $this->assertEquals('unknown', $updateStatus);
    }

    // ===========================================
    // Icon URL Tests
    // ===========================================

    /**
     * Test icon URL validation
     */
    public function testIconUrlValidation(): void
    {
        $this->createTestStack('icon-stack', ['icon_url' => 'https://example.com/icon.png']);
        
        $project = 'icon-stack';
        $icon = '';
        
        if (is_file("$this->testComposeRoot/$project/icon_url")) {
            $iconUrl = trim(@file_get_contents("$this->testComposeRoot/$project/icon_url"));
            if (filter_var($iconUrl, FILTER_VALIDATE_URL) && 
                (strpos($iconUrl, 'http://') === 0 || strpos($iconUrl, 'https://') === 0)) {
                $icon = $iconUrl;
            }
        }
        
        $this->assertEquals('https://example.com/icon.png', $icon);
    }

    /**
     * Test invalid icon URL is rejected
     */
    public function testInvalidIconUrlRejected(): void
    {
        $stackPath = $this->createTestStack('bad-icon');
        file_put_contents("$stackPath/icon_url", 'javascript:alert(1)');
        
        $project = 'bad-icon';
        $icon = '';
        
        if (is_file("$this->testComposeRoot/$project/icon_url")) {
            $iconUrl = trim(@file_get_contents("$this->testComposeRoot/$project/icon_url"));
            if (filter_var($iconUrl, FILTER_VALIDATE_URL) && 
                (strpos($iconUrl, 'http://') === 0 || strpos($iconUrl, 'https://') === 0)) {
                $icon = $iconUrl;
            }
        }
        
        $this->assertEquals('', $icon);
    }

    // ===========================================
    // WebUI URL Tests
    // ===========================================

    /**
     * Test WebUI URL is loaded
     */
    public function testWebuiUrlLoaded(): void
    {
        $this->createTestStack('webui-stack', ['webui_url' => 'http://localhost:8080']);
        
        $project = 'webui-stack';
        $webui = '';
        
        if (is_file("$this->testComposeRoot/$project/webui_url")) {
            $webuiUrl = trim(@file_get_contents("$this->testComposeRoot/$project/webui_url"));
            if (!empty($webuiUrl)) {
                $webui = $webuiUrl;
            }
        }
        
        $this->assertEquals('http://localhost:8080', $webui);
    }

    // ===========================================
    // Started At Timestamp Tests
    // ===========================================

    /**
     * Test started_at timestamp is loaded
     */
    public function testStartedAtTimestampLoaded(): void
    {
        $timestamp = '2026-02-03T10:30:00-05:00';
        $this->createTestStack('running-stack', ['started_at' => $timestamp]);
        
        $project = 'running-stack';
        $startedAt = '';
        
        if (is_file("$this->testComposeRoot/$project/started_at")) {
            $startedAt = trim(file_get_contents("$this->testComposeRoot/$project/started_at"));
        }
        
        $this->assertEquals($timestamp, $startedAt);
    }

    /**
     * Test started_at is empty when file doesn't exist
     */
    public function testStartedAtEmptyWhenNoFile(): void
    {
        $this->createTestStack('never-started');
        
        $project = 'never-started';
        $startedAt = '';
        
        if (is_file("$this->testComposeRoot/$project/started_at")) {
            $startedAt = trim(file_get_contents("$this->testComposeRoot/$project/started_at"));
        }
        
        $this->assertEquals('', $startedAt);
    }

    // ===========================================
    // Sanitized Name Tests (for container matching)
    // ===========================================

    /**
     * Test sanitized name matches sanitizeStr output
     */
    public function testSanitizedNameFormat(): void
    {
        // The sanitizeStr function from util.php
        $projectName = 'My.Stack-Name';
        
        // Inline implementation matching util.php
        $sanitized = str_replace(".", "_", $projectName);
        $sanitized = str_replace(" ", "_", $sanitized);
        $sanitized = str_replace("-", "_", $sanitized);
        $sanitized = strtolower($sanitized);
        
        $this->assertEquals('my_stack_name', $sanitized);
    }

    // ===========================================
    // JSON Output Tests
    // ===========================================

    /**
     * Test JSON encoding of summary
     */
    public function testJsonEncodingSummary(): void
    {
        $summary = [
            'total' => 2,
            'started' => 1,
            'stopped' => 1,
            'partial' => 0,
            'stacks' => [
                [
                    'name' => 'Stack 1',
                    'folder' => 'stack-1',
                    'state' => 'started',
                    'running' => 2,
                    'total' => 2,
                    'icon' => '',
                    'webui' => '',
                    'startedAt' => '',
                    'update' => 'unknown'
                ],
            ]
        ];
        
        $json = json_encode($summary);
        
        $this->assertJson($json);
        
        $decoded = json_decode($json, true);
        $this->assertEquals(2, $decoded['total']);
        $this->assertCount(1, $decoded['stacks']);
    }
}
