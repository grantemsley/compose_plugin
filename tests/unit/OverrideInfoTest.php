<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

// Load the actual source file via stream wrapper
require_once '/usr/local/emhttp/plugins/compose.manager/php/util.php';

class OverrideInfoTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRoot = $this->createTempDir();
    }

    public function testFromStackCreatesInstance(): void
    {
        $stack = 'teststack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertInstanceOf(\OverrideInfo::class, $info);
    }

    public function testComputedNameDefault(): void
    {
        $stack = 'stack1';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertStringContainsString('override', $info->computedName);
    }

    public function testProjectOverridePath(): void
    {
        $stack = 'stack2';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertStringContainsString($stackDir, $info->projectOverride);
    }

    public function testIndirectOverridePath(): void
    {
        $stack = 'stack3';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $indirectTarget = $this->tempRoot . '/indirect_target';
        mkdir($indirectTarget);
        file_put_contents($stackDir . '/indirect', $indirectTarget);
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertEquals($indirectTarget . '/' . $info->computedName, $info->indirectOverride);
    }

    public function testUseIndirectTrueWhenIndirectOverrideExists(): void
    {
        $stack = 'stack4';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $indirectTarget = $this->tempRoot . '/indirect_target2';
        mkdir($indirectTarget);
        file_put_contents($stackDir . '/indirect', $indirectTarget);
        $overridePath = $indirectTarget . '/compose.override.yaml';
        file_put_contents($overridePath, '# override');
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertTrue($info->useIndirect);
    }

    public function testMismatchIndirectLegacy(): void
    {
        $stack = 'stack5';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $indirectTarget = $this->tempRoot . '/indirect_target3';
        mkdir($indirectTarget);
        file_put_contents($stackDir . '/indirect', $indirectTarget);
        $legacyPath = $indirectTarget . '/docker-compose.override.yml';
        file_put_contents($legacyPath, '# legacy');
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertTrue($info->mismatchIndirectLegacy);
    }

    public function testGetOverridePathPrefersIndirect(): void
    {
        $stack = 'stack6';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $indirectTarget = $this->tempRoot . '/indirect_target4';
        mkdir($indirectTarget);
        file_put_contents($stackDir . '/indirect', $indirectTarget);
        $overridePath = $indirectTarget . '/compose.override.yaml';
        file_put_contents($overridePath, '# override');
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertEquals($overridePath, $info->getOverridePath());
    }

    public function testGetOverridePathFallsBackToProject(): void
    {
        $stack = 'stack7';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $overridePath = $stackDir . '/compose.override.yaml';
        file_put_contents($overridePath, '# override');
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertEquals($overridePath, $info->getOverridePath());
    }

    public function testLegacyProjectOverrideMigration(): void
    {
        $stack = 'stack8';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $legacyPath = $stackDir . '/docker-compose.override.yml';
        file_put_contents($legacyPath, '# legacy');
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertFileDoesNotExist($legacyPath);
        $this->assertFileExists($info->projectOverride);
    }

    public function testLegacyProjectOverrideStaleRemoval(): void
    {
        $stack = 'stack9';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $legacyPath = $stackDir . '/docker-compose.override.yml';
        $computedPath = $stackDir . '/compose.override.yaml';
        file_put_contents($legacyPath, '# legacy');
        file_put_contents($computedPath, '# computed');
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertFileExists($computedPath);
        $this->assertFileDoesNotExist($legacyPath);
        $this->assertFileExists($legacyPath . '.bak');
    }
}
