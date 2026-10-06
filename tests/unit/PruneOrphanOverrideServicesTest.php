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
 * that has no image, and otherwise prints the compose file's services. Given
 * no -f flag, it finds compose.override.yaml in the project directory by
 * itself, as compose does.
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
refuse() {
  echo 'service "gone" has neither an image nor a build context specified: invalid compose project' >&2
  exit 1
}
project_directory=''
files_named=no
previous=''
for arg in "$@"; do
  case "$arg" in
    *override*) refuse ;;
  esac
  if [ "$previous" = '--project-directory' ]; then
    project_directory="$arg"
  fi
  if [ "$previous" = '-f' ]; then
    files_named=yes
  fi
  previous="$arg"
done
if [ "$files_named" = no ] && [ -f "$project_directory/compose.override.yaml" ]; then
  refuse
fi
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

    public function testServiceIsPrunedWhenTheStackUsesDefaultFileDiscovery(): void
    {
        $stackDir = $this->root . '/mystack';
        mkdir($stackDir);
        file_put_contents($stackDir . '/compose.yaml', "services:\n  web:\n    image: traefik/whoami\n");
        file_put_contents($stackDir . '/use_default_compose_files', 'true');
        $stack = \StackInfo::fromProject($this->root, 'mystack');
        $this->assertTrue($stack->useDefaultComposeFileDiscovery());
        $override = (string) $stack->getOverridePath();
        $this->assertSame($stackDir . '/compose.override.yaml', $override);
        file_put_contents(
            $override,
            "services:\n  web:\n    labels:\n      net.unraid.docker.webui: http://x\n"
            . "  gone:\n    labels:\n      net.unraid.docker.webui: http://y\n"
        );
        \StackInfo::clearCache();
        $stack = \StackInfo::fromProject($this->root, 'mystack');

        $result = $stack->pruneOrphanOverrideServices();

        $this->assertSame(['gone'], $result['removed']);
        $this->assertStringNotContainsString('gone', (string) file_get_contents($override));
        // The compose file was named, so compose could not pick up the override by itself.
        $calls = (string) file_get_contents($this->bin . '/calls.log');
        $this->assertStringContainsString("-f {$stackDir}/compose.yaml", $calls);
        $this->assertStringContainsString("--project-directory {$stackDir}", $calls);
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
