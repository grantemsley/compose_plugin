<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';

/**
 * StackInfo::pruneOrphanOverrideServices() with a stand-in docker CLI on PATH.
 *
 * The stand-in behaves like docker compose does for `config --services`: it
 * fails when it is given the managed override with an entry for a service
 * that has no image, and otherwise prints the compose file's services.
 */
final class PruneOrphanOverrideServicesTest extends TestCase
{
    private string $root;
    private string $bin;
    private string $originalPath;

    protected function setUp(): void
    {
        parent::setUp();
        \StackInfo::clearCache();
        $this->root = $this->createTempDir();
        $this->bin = $this->createTempDir();
        $this->originalPath = (string) getenv('PATH');

        file_put_contents($this->bin . '/docker', <<<'SH'
#!/bin/bash
# Record the call, then answer like docker compose config --services.
printf '%s\n' "$*" >> "$(dirname "$0")/calls.log"
for arg in "$@"; do
  case "$arg" in
    *override*)
      echo 'service "gone" has neither an image nor a build context specified: invalid compose project' >&2
      exit 1
      ;;
  esac
done
echo web
SH);
        chmod($this->bin . '/docker', 0755);
        putenv('PATH=' . $this->bin . ':' . $this->originalPath);
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->originalPath);
        parent::tearDown();
    }

    public function testServiceNoLongerInTheComposeFileIsPrunedFromTheManagedOverride(): void
    {
        $stackDir = $this->root . '/mystack';
        mkdir($stackDir);
        file_put_contents($stackDir . '/compose.yaml', "services:\n  web:\n    image: traefik/whoami\n");
        $stack = \StackInfo::fromProject($this->root, 'mystack');
        $override = (string) $stack->getOverridePath();
        file_put_contents(
            $override,
            "services:\n  web:\n    labels:\n      net.unraid.docker.webui: http://x\n"
            . "  gone:\n    labels:\n      net.unraid.docker.webui: http://y\n"
        );
        \StackInfo::clearCache();
        $stack = \StackInfo::fromProject($this->root, 'mystack');

        $result = $stack->pruneOrphanOverrideServices();

        $this->assertSame(['gone'], $result['removed']);
        $content = (string) file_get_contents($override);
        $this->assertStringNotContainsString('gone', $content);
        $this->assertStringContainsString('web', $content);
        // The services were asked for without the override.
        $this->assertStringNotContainsString($override, (string) file_get_contents($this->bin . '/calls.log'));
    }

    public function testOverrideWithOnlyCurrentServicesIsLeftAlone(): void
    {
        $stackDir = $this->root . '/mystack';
        mkdir($stackDir);
        file_put_contents($stackDir . '/compose.yaml', "services:\n  web:\n    image: traefik/whoami\n");
        $stack = \StackInfo::fromProject($this->root, 'mystack');
        $override = (string) $stack->getOverridePath();
        $content = "services:\n  web:\n    labels:\n      net.unraid.docker.webui: http://x\n";
        file_put_contents($override, $content);
        \StackInfo::clearCache();

        $result = \StackInfo::fromProject($this->root, 'mystack')->pruneOrphanOverrideServices();

        $this->assertFalse($result['changed']);
        $this->assertSame($content, file_get_contents($override));
    }
}
