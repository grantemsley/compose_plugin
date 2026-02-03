<?php

/**
 * Unit Tests for Compose Manager Utility Functions
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

// Include the util.php file to test its functions
require_once __DIR__ . '/../../source/compose.manager/php/util.php';

class UtilTest extends TestCase
{
    /**
     * Test sanitizeStr removes dots
     */
    public function testSanitizeStrRemovesDots(): void
    {
        $result = sanitizeStr('my.stack.name');
        $this->assertEquals('my_stack_name', $result);
    }

    /**
     * Test sanitizeStr removes spaces
     */
    public function testSanitizeStrRemovesSpaces(): void
    {
        $result = sanitizeStr('my stack name');
        $this->assertEquals('my_stack_name', $result);
    }

    /**
     * Test sanitizeStr removes dashes
     */
    public function testSanitizeStrRemovesDashes(): void
    {
        $result = sanitizeStr('my-stack-name');
        $this->assertEquals('my_stack_name', $result);
    }

    /**
     * Test sanitizeStr converts to lowercase
     */
    public function testSanitizeStrConvertsToLowercase(): void
    {
        $result = sanitizeStr('MyStackName');
        $this->assertEquals('mystackname', $result);
    }

    /**
     * Test sanitizeStr handles combined cases
     */
    public function testSanitizeStrCombined(): void
    {
        $result = sanitizeStr('My.Stack-Name Here');
        $this->assertEquals('my_stack_name_here', $result);
    }

    /**
     * Test isIndirect returns false when no indirect file exists
     */
    public function testIsIndirectReturnsFalseWhenNoFile(): void
    {
        $tempDir = $this->createTempDir();
        
        $result = isIndirect($tempDir);
        
        $this->assertFalse($result);
    }

    /**
     * Test isIndirect returns true when indirect file exists
     */
    public function testIsIndirectReturnsTrueWhenFileExists(): void
    {
        $tempDir = $this->createTempDir();
        file_put_contents("$tempDir/indirect", '/mnt/user/appdata/realpath');
        
        $result = isIndirect($tempDir);
        
        $this->assertTrue($result);
    }

    /**
     * Test getPath returns basePath when not indirect
     */
    public function testGetPathReturnsBasePathWhenNotIndirect(): void
    {
        $tempDir = $this->createTempDir();
        
        $result = getPath($tempDir);
        
        $this->assertEquals($tempDir, $result);
    }

    /**
     * Test getPath returns indirect content when indirect file exists
     */
    public function testGetPathReturnsIndirectContent(): void
    {
        $tempDir = $this->createTempDir();
        $targetPath = '/mnt/user/appdata/mystack';
        file_put_contents("$tempDir/indirect", $targetPath);
        
        $result = getPath($tempDir);
        
        $this->assertEquals($targetPath, $result);
    }

    /**
     * Test getStackLastResult returns null when no result file
     */
    public function testGetStackLastResultReturnsNullWhenNoFile(): void
    {
        $tempDir = $this->createTempDir();
        
        $result = getStackLastResult($tempDir);
        
        $this->assertNull($result);
    }

    /**
     * Test getStackLastResult returns parsed JSON when file exists
     */
    public function testGetStackLastResultReturnsJson(): void
    {
        $tempDir = $this->createTempDir();
        $resultData = [
            'result' => 'success',
            'exit_code' => 0,
            'operation' => 'up',
            'timestamp' => '2026-02-03T10:00:00-05:00'
        ];
        file_put_contents("$tempDir/last_result.json", json_encode($resultData));
        
        $result = getStackLastResult($tempDir);
        
        $this->assertIsArray($result);
        $this->assertEquals('success', $result['result']);
        $this->assertEquals(0, $result['exit_code']);
        $this->assertEquals('up', $result['operation']);
    }

    /**
     * Test getStackLastResult handles invalid JSON gracefully
     */
    public function testGetStackLastResultHandlesInvalidJson(): void
    {
        $tempDir = $this->createTempDir();
        file_put_contents("$tempDir/last_result.json", 'not valid json');
        
        $result = getStackLastResult($tempDir);
        
        $this->assertNull($result);
    }
}
