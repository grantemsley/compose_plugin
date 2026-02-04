<?php

/**
 * Unit Tests for Compose Utility Functions (REAL SOURCE)
 * 
 * Tests the actual source: source/compose.manager/php/compose_util.php
 * The file is loaded via includeWithSwitch() to safely bypass the switch($_POST['action']) block.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PluginTests\Mocks\FunctionMocks;

// Load the actual source file via stream wrapper using includeWithSwitch()
// This safely includes compose_util.php which has a switch($_POST['action']) block
includeWithSwitch('/usr/local/emhttp/plugins/compose.manager/php/compose_util.php');

/**
 * Tests for compose_util.php functions
 * 
 * Note: compose_util.php contains these functions:
 * - logger($string) - calls system logger
 * - execComposeCommandInTTY($cmd, $debug) - runs ttyd
 * - echoComposeCommand($action) - echoes compose command
 * - echoComposeCommandMultiple($action, $paths) - echoes multiple compose commands
 * 
 * These functions mostly echo output and execute external commands, so we test
 * with output capturing and mock filesystem setup.
 */
class ComposeUtilTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up required globals for compose_util functions
        global $plugin_root, $sName, $socket_name;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager';
        $sName = 'compose.manager';
        $socket_name = 'compose_test';
        
        // Set up plugin config
        FunctionMocks::setPluginConfig('compose.manager', [
            'DEBUG_TO_LOG' => 'false',
            'OUTPUTSTYLE' => 'nchan',
        ]);
    }

    // ===========================================
    // echoComposeCommand() Tests
    // ===========================================

    /**
     * Test echoComposeCommand when array is not started
     */
    public function testEchoComposeCommandArrayNotStarted(): void
    {
        global $compose_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;
        
        // Create mock var.ini with stopped array
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STOPPED\nfsState=Stopped\n");
        
        // Update the stream wrapper mapping
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");
        
        // Set POST data
        $_POST['path'] = urlencode($tempDir . '/test-stack');
        
        // Capture output
        ob_start();
        echoComposeCommand('up');
        $output = ob_get_clean();
        
        // Should return arrayNotStarted script path
        $this->assertStringContainsString('arrayNotStarted.sh', $output);
        
        // Cleanup
        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path']);
    }

    /**
     * Test echoComposeCommand generates proper command format for nchan
     */
    public function testEchoComposeCommandNchanFormat(): void
    {
        global $compose_root, $plugin_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;
        
        // Create stack directory with compose file
        $stackDir = "$tempDir/test-stack";
        mkdir($stackDir, 0755, true);
        file_put_contents("$stackDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/name", "TestStack");
        
        // Ensure array is started
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");
        
        // Set POST data
        $_POST['path'] = urlencode($stackDir);
        $_POST['profile'] = '';
        
        // Capture output
        ob_start();
        echoComposeCommand('up');
        $output = ob_get_clean();
        
        // Should be nchan format with arg parameters
        $this->assertStringContainsString('&arg', $output);
        $this->assertStringContainsString('-cup', $output);
        
        // Cleanup
        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path'], $_POST['profile']);
    }

    /**
     * Test echoComposeCommand with profile
     */
    public function testEchoComposeCommandWithProfile(): void
    {
        global $compose_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;
        
        // Create stack directory
        $stackDir = "$tempDir/test-stack";
        mkdir($stackDir, 0755, true);
        file_put_contents("$stackDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/name", "TestStack");
        
        // Ensure array is started
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");
        
        // Set POST data with profile
        $_POST['path'] = urlencode($stackDir);
        $_POST['profile'] = urlencode('dev');
        
        // Capture output
        ob_start();
        echoComposeCommand('up');
        $output = ob_get_clean();
        
        // Should include profile flag
        $this->assertStringContainsString('-g dev', $output);
        
        // Cleanup
        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path'], $_POST['profile']);
    }

    /**
     * Test echoComposeCommand with multiple profiles
     */
    public function testEchoComposeCommandWithMultipleProfiles(): void
    {
        global $compose_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;
        
        // Create stack directory
        $stackDir = "$tempDir/test-stack";
        mkdir($stackDir, 0755, true);
        file_put_contents("$stackDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/name", "TestStack");
        
        // Ensure array is started
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");
        
        // Set POST data with multiple profiles
        $_POST['path'] = urlencode($stackDir);
        $_POST['profile'] = urlencode('dev,prod');
        
        // Capture output
        ob_start();
        echoComposeCommand('up');
        $output = ob_get_clean();
        
        // Should include both profile flags
        $this->assertStringContainsString('-g dev', $output);
        $this->assertStringContainsString('-g prod', $output);
        
        // Cleanup
        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path'], $_POST['profile']);
    }

    /**
     * Test echoComposeCommand with indirect stack
     */
    public function testEchoComposeCommandWithIndirect(): void
    {
        global $compose_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;
        
        // Create indirect target directory
        $indirectDir = $this->createTempDir();
        file_put_contents("$indirectDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        
        // Create stack directory with indirect pointer
        $stackDir = "$tempDir/test-stack";
        mkdir($stackDir, 0755, true);
        file_put_contents("$stackDir/indirect", $indirectDir);
        file_put_contents("$stackDir/name", "TestStack");
        
        // Ensure array is started
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");
        
        // Set POST data
        $_POST['path'] = urlencode($stackDir);
        $_POST['profile'] = '';
        
        // Capture output
        ob_start();
        echoComposeCommand('up');
        $output = ob_get_clean();
        
        // Should use -d flag for directory path
        $this->assertStringContainsString('-d', $output);
        $this->assertStringContainsString($indirectDir, $output);
        
        // Cleanup
        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path'], $_POST['profile']);
    }

    /**
     * Test echoComposeCommand with override file
     */
    public function testEchoComposeCommandWithOverrideFile(): void
    {
        global $compose_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;
        
        // Create stack directory with compose and override files
        $stackDir = "$tempDir/test-stack";
        mkdir($stackDir, 0755, true);
        file_put_contents("$stackDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/docker-compose.override.yml", "services:\n  web:\n    ports:\n      - 80:80\n");
        file_put_contents("$stackDir/name", "TestStack");
        
        // Ensure array is started
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");
        
        // Set POST data
        $_POST['path'] = urlencode($stackDir);
        $_POST['profile'] = '';
        
        // Capture output
        ob_start();
        echoComposeCommand('up');
        $output = ob_get_clean();
        
        // Should include override file
        $this->assertStringContainsString('docker-compose.override.yml', $output);
        
        // Cleanup
        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path'], $_POST['profile']);
    }

    /**
     * Test echoComposeCommand with custom env path
     */
    public function testEchoComposeCommandWithEnvPath(): void
    {
        global $compose_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;
        
        // Create stack directory with envpath file
        $stackDir = "$tempDir/test-stack";
        mkdir($stackDir, 0755, true);
        file_put_contents("$stackDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/name", "TestStack");
        file_put_contents("$stackDir/envpath", "/custom/path/.env");
        
        // Ensure array is started
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");
        
        // Set POST data
        $_POST['path'] = urlencode($stackDir);
        $_POST['profile'] = '';
        
        // Capture output
        ob_start();
        echoComposeCommand('up');
        $output = ob_get_clean();
        
        // Should include env path
        $this->assertStringContainsString('-e/custom/path/.env', $output);
        
        // Cleanup
        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path'], $_POST['profile']);
    }

    /**
     * @dataProvider actionsProvider
     * Test various compose actions
     */
    public function testEchoComposeCommandActions(string $action, string $expectedArg): void
    {
        global $compose_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;
        
        // Create stack directory
        $stackDir = "$tempDir/test-stack";
        mkdir($stackDir, 0755, true);
        file_put_contents("$stackDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/name", "TestStack");
        
        // Ensure array is started
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");
        
        // Set POST data
        $_POST['path'] = urlencode($stackDir);
        $_POST['profile'] = '';
        
        // Capture output
        ob_start();
        echoComposeCommand($action);
        $output = ob_get_clean();
        
        // Should include the expected action arg
        $this->assertStringContainsString($expectedArg, $output);
        
        // Cleanup
        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path'], $_POST['profile']);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function actionsProvider(): array
    {
        return [
            'up action' => ['up', '-cup'],
            'down action' => ['down', '-cdown'],
            'pull action' => ['pull', '-cpull'],
            'stop action' => ['stop', '-cstop'],
            'logs action' => ['logs', '-clogs'],
            'update action' => ['update', '-cupdate'],
        ];
    }
}
