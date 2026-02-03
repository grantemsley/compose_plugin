<?php

/**
 * Unit Tests for Compose Utility Functions (compose_util.php)
 * 
 * Tests utility functions used for building compose commands.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PluginTests\Mocks\FunctionMocks;

/**
 * Tests for compose_util.php extracted functions
 */
class ComposeUtilTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Define extracted functions if not already defined
        if (!function_exists('buildComposeCommandArgs')) {
            require_once __DIR__ . '/compose_util_functions.php';
        }
    }

    // ===========================================
    // buildComposeCommandArgs() Tests
    // ===========================================

    /**
     * Test building basic compose command args
     */
    public function testBuildComposeCommandArgsBasic(): void
    {
        $tempDir = $this->createTempDir();
        file_put_contents("$tempDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$tempDir/name", "My Stack");
        
        $result = buildComposeCommandArgs($tempDir, 'up', null, false);
        
        $this->assertIsArray($result);
        $this->assertContains('-cup', $result);
        $this->assertContains('-pmy_stack', $result);
    }

    /**
     * Test building compose command with profile
     */
    public function testBuildComposeCommandArgsWithProfile(): void
    {
        $tempDir = $this->createTempDir();
        file_put_contents("$tempDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$tempDir/name", "TestStack");
        
        $result = buildComposeCommandArgs($tempDir, 'up', 'dev', false);
        
        $this->assertContains('-g dev', $result);
    }

    /**
     * Test building compose command with multiple profiles
     */
    public function testBuildComposeCommandArgsMultipleProfiles(): void
    {
        $tempDir = $this->createTempDir();
        file_put_contents("$tempDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$tempDir/name", "TestStack");
        
        $result = buildComposeCommandArgs($tempDir, 'up', 'dev,prod', false);
        
        $this->assertContains('-g dev', $result);
        $this->assertContains('-g prod', $result);
    }

    /**
     * Test building compose command with override file
     */
    public function testBuildComposeCommandArgsWithOverride(): void
    {
        $tempDir = $this->createTempDir();
        file_put_contents("$tempDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$tempDir/docker-compose.override.yml", "services:\n  web:\n    ports:\n      - 80:80\n");
        file_put_contents("$tempDir/name", "TestStack");
        
        $result = buildComposeCommandArgs($tempDir, 'up', null, false);
        
        // Should include the override file
        $hasOverride = false;
        foreach ($result as $arg) {
            if (strpos($arg, 'docker-compose.override.yml') !== false) {
                $hasOverride = true;
                break;
            }
        }
        $this->assertTrue($hasOverride, 'Override file should be included');
    }

    /**
     * Test building compose command with custom env path
     */
    public function testBuildComposeCommandArgsWithEnvPath(): void
    {
        $tempDir = $this->createTempDir();
        file_put_contents("$tempDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$tempDir/name", "TestStack");
        file_put_contents("$tempDir/envpath", "/custom/path/.env");
        
        $result = buildComposeCommandArgs($tempDir, 'up', null, false);
        
        $this->assertContains('-e/custom/path/.env', $result);
    }

    /**
     * Test building compose command with indirect path
     */
    public function testBuildComposeCommandArgsWithIndirect(): void
    {
        $tempDir = $this->createTempDir();
        $indirectDir = $this->createTempDir();
        
        file_put_contents("$indirectDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$tempDir/indirect", $indirectDir);
        file_put_contents("$tempDir/name", "TestStack");
        
        $result = buildComposeCommandArgs($tempDir, 'up', null, false);
        
        // Should use -d flag for directory
        $hasDir = false;
        foreach ($result as $arg) {
            if (strpos($arg, '-d') === 0) {
                $hasDir = true;
                break;
            }
        }
        $this->assertTrue($hasDir, 'Should use -d flag for indirect');
    }

    /**
     * Test building compose command with debug flag
     */
    public function testBuildComposeCommandArgsWithDebug(): void
    {
        $tempDir = $this->createTempDir();
        file_put_contents("$tempDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$tempDir/name", "TestStack");
        
        $result = buildComposeCommandArgs($tempDir, 'up', null, true);
        
        $this->assertContains('--debug', $result);
    }

    // ===========================================
    // Project Name Sanitization Tests
    // ===========================================

    /**
     * Test project name is sanitized from folder name
     */
    public function testProjectNameFromFolder(): void
    {
        $tempDir = $this->createTempDir();
        // Don't create a name file - should use folder name
        file_put_contents("$tempDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        
        $result = buildComposeCommandArgs($tempDir, 'up', null, false);
        
        // Project name should be based on the temp dir basename, sanitized
        $hasProjectName = false;
        foreach ($result as $arg) {
            if (strpos($arg, '-p') === 0) {
                $hasProjectName = true;
                // Should be lowercase and have special chars replaced
                $this->assertMatchesRegularExpression('/^-p[a-z0-9_]+$/', $arg);
                break;
            }
        }
        $this->assertTrue($hasProjectName, 'Should have project name argument');
    }

    /**
     * Test project name special characters handling
     */
    public function testProjectNameSpecialChars(): void
    {
        $tempDir = $this->createTempDir();
        file_put_contents("$tempDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$tempDir/name", "My.Stack-Name Here");
        
        $result = buildComposeCommandArgs($tempDir, 'up', null, false);
        
        // Find project name arg
        foreach ($result as $arg) {
            if (strpos($arg, '-p') === 0) {
                // Should be sanitized: lowercase, dots/dashes/spaces replaced with underscore
                $this->assertEquals('-pmy_stack_name_here', $arg);
                break;
            }
        }
    }

    // ===========================================
    // Action Tests
    // ===========================================

    /**
     * @dataProvider actionsProvider
     */
    public function testVariousActions(string $action, string $expectedArg): void
    {
        $tempDir = $this->createTempDir();
        file_put_contents("$tempDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$tempDir/name", "test");
        
        $result = buildComposeCommandArgs($tempDir, $action, null, false);
        
        $this->assertContains($expectedArg, $result);
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
            'restart action' => ['restart', '-crestart'],
            'build action' => ['build', '-cbuild'],
        ];
    }

    // ===========================================
    // sanitizeStackName() Tests
    // ===========================================

    /**
     * Test sanitizeStackName removes quotes
     */
    public function testSanitizeStackNameRemovesQuotes(): void
    {
        $result = sanitizeStackName('My "Stack" Name');
        $this->assertEquals('My_Stack_Name', $result);
    }

    /**
     * Test sanitizeStackName removes single quotes
     */
    public function testSanitizeStackNameRemovesSingleQuotes(): void
    {
        $result = sanitizeStackName("My 'Stack' Name");
        $this->assertEquals('My_Stack_Name', $result);
    }

    /**
     * Test sanitizeStackName removes ampersands
     */
    public function testSanitizeStackNameRemovesAmpersands(): void
    {
        $result = sanitizeStackName('Tom & Jerry');
        // Ampersand is removed, leaving "Tom  Jerry" which collapses to "Tom_Jerry"
        $this->assertEquals('Tom_Jerry', $result);
    }

    /**
     * Test sanitizeStackName removes parentheses
     */
    public function testSanitizeStackNameRemovesParentheses(): void
    {
        $result = sanitizeStackName('Stack (test)');
        $this->assertEquals('Stack_test', $result);
    }

    /**
     * Test sanitizeStackName collapses multiple spaces
     */
    public function testSanitizeStackNameCollapsesSpaces(): void
    {
        $result = sanitizeStackName('My    Stack    Name');
        $this->assertEquals('My_Stack_Name', $result);
    }

    /**
     * Test sanitizeStackName with all special chars combined
     */
    public function testSanitizeStackNameCombined(): void
    {
        $result = sanitizeStackName('My "Test" & (Stack)  Name');
        // All special chars removed, multiple spaces collapsed to single underscore
        $this->assertEquals('My_Test_Stack_Name', $result);
    }

    // ===========================================
    // formatComposeCommandForOutput() Tests
    // ===========================================

    /**
     * Test formatComposeCommandForOutput basic formatting
     */
    public function testFormatComposeCommandForOutputBasic(): void
    {
        $args = ['-cup', '-pmystack', '-f/path/to/compose.yml'];
        
        $result = formatComposeCommandForOutput($args);
        
        $this->assertStringContainsString('&arg1=-cup', $result);
        $this->assertStringContainsString('&arg2=-pmystack', $result);
        $this->assertStringContainsString('&arg3=-f/path/to/compose.yml', $result);
    }

    /**
     * Test formatComposeCommandForOutput with profile args
     */
    public function testFormatComposeCommandForOutputWithProfiles(): void
    {
        $args = ['-cup', '-pmystack', '-g dev', '-g prod'];
        
        $result = formatComposeCommandForOutput($args);
        
        // Profile args don't start with - so they get appended
        $this->assertStringContainsString('-g dev', $result);
        $this->assertStringContainsString('-g prod', $result);
    }

    /**
     * Test formatComposeCommandForOutput empty array
     */
    public function testFormatComposeCommandForOutputEmpty(): void
    {
        $args = [];
        
        $result = formatComposeCommandForOutput($args);
        
        $this->assertEquals('', $result);
    }
}
