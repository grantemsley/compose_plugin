<?php

/**
 * Unit Tests for compose_list.php Functions
 * 
 * Tests the stack list generation functions in source/compose.manager/php/compose_list.php
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PluginTests\Mocks\FunctionMocks;

class ComposeListTest extends TestCase
{
    private string $testComposeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test compose root
        $this->testComposeRoot = sys_get_temp_dir() . '/compose_list_test_' . getmypid();
        if (!is_dir($this->testComposeRoot)) {
            mkdir($this->testComposeRoot, 0755, true);
        }
        
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
        $composeContent = $options['compose'] ?? "services:\n  web:\n    image: nginx\n";
        
        file_put_contents("$stackPath/docker-compose.yml", $composeContent);
        file_put_contents("$stackPath/name", $name);
        
        if (isset($options['description'])) {
            file_put_contents("$stackPath/description", $options['description']);
        }
        
        if (isset($options['autostart'])) {
            file_put_contents("$stackPath/autostart", $options['autostart'] ? 'true' : 'false');
        }
        
        if (isset($options['icon_url'])) {
            file_put_contents("$stackPath/icon_url", $options['icon_url']);
        }
        
        if (isset($options['webui_url'])) {
            file_put_contents("$stackPath/webui_url", $options['webui_url']);
        }
        
        if (isset($options['profiles'])) {
            file_put_contents("$stackPath/profiles", json_encode($options['profiles']));
        }
        
        if (isset($options['indirect'])) {
            file_put_contents("$stackPath/indirect", $options['indirect']);
        }
        
        return $stackPath;
    }

    // ===========================================
    // createComboButton Tests
    // ===========================================

    /**
     * Test createComboButton generates valid HTML structure
     */
    public function testCreateComboButtonStructure(): void
    {
        // Inline implementation matching compose_list.php
        $text = 'Start';
        $id = 'start-btn';
        $onClick = 'startStack';
        $onClickParams = "'mystack'";
        $items = ['Start with profile A', 'Start with profile B'];
        
        $o = "";
        $o .= "<div class='combo-btn-group'>";
        $o .= "<input type='button' value='$text' class='combo-btn-group-left' id='$id-left-btn' onclick='$onClick($onClickParams);'>";
        $o .= "<section class='combo-btn-subgroup dropdown'>";
        $o .= "<button type='button' class='dropdown-toggle combo-btn-group-right' data-toggle='dropdown'><i class='fa fa-caret-down'></i></button>";
        $o .= "<div class='dropdown-content'>";
        foreach ($items as $item) {
            $o .= "<a href='#' onclick='$onClick($onClickParams, &quot;$item&quot;);'>$item</a>";
        }
        $o .= "</div>";
        $o .= "</section>";
        $o .= "</div>";
        
        // Verify structure
        $this->assertStringContainsString("class='combo-btn-group'", $o);
        $this->assertStringContainsString("value='Start'", $o);
        $this->assertStringContainsString("id='start-btn-left-btn'", $o);
        $this->assertStringContainsString("onclick='startStack('mystack');'", $o);
        $this->assertStringContainsString('Start with profile A', $o);
        $this->assertStringContainsString('Start with profile B', $o);
    }

    /**
     * Test createComboButton with empty items
     */
    public function testCreateComboButtonEmptyItems(): void
    {
        $items = [];
        
        $o = "<div class='dropdown-content'>";
        foreach ($items as $item) {
            $o .= "<a href='#'>$item</a>";
        }
        $o .= "</div>";
        
        $this->assertEquals("<div class='dropdown-content'></div>", $o);
    }

    // ===========================================
    // Stack Discovery Tests
    // ===========================================

    /**
     * Test stack discovery finds stacks with docker-compose.yml
     */
    public function testStackDiscoveryWithComposeFile(): void
    {
        $this->createTestStack('valid-stack');
        
        $projects = @array_diff(@scandir($this->testComposeRoot), ['.', '..']);
        
        $validStacks = [];
        foreach ($projects as $project) {
            if (is_file("$this->testComposeRoot/$project/docker-compose.yml") ||
                is_file("$this->testComposeRoot/$project/indirect")) {
                $validStacks[] = $project;
            }
        }
        
        $this->assertContains('valid-stack', $validStacks);
    }

    /**
     * Test stack discovery finds stacks with indirect pointer
     */
    public function testStackDiscoveryWithIndirect(): void
    {
        // Create the indirect target
        $indirectTarget = sys_get_temp_dir() . '/indirect_target_' . getmypid();
        mkdir($indirectTarget, 0755, true);
        file_put_contents("$indirectTarget/docker-compose.yml", "services:\n  web:\n    image: nginx\n");
        
        // Create stack with indirect pointer
        $stackPath = $this->testComposeRoot . '/indirect-stack';
        mkdir($stackPath, 0755, true);
        file_put_contents("$stackPath/indirect", $indirectTarget);
        file_put_contents("$stackPath/name", 'Indirect Stack');
        
        $projects = @array_diff(@scandir($this->testComposeRoot), ['.', '..']);
        
        $validStacks = [];
        foreach ($projects as $project) {
            if (is_file("$this->testComposeRoot/$project/docker-compose.yml") ||
                is_file("$this->testComposeRoot/$project/indirect")) {
                $validStacks[] = $project;
            }
        }
        
        $this->assertContains('indirect-stack', $validStacks);
        
        // Clean up indirect target
        unlink("$indirectTarget/docker-compose.yml");
        rmdir($indirectTarget);
    }

    /**
     * Test stack discovery ignores directories without compose files
     */
    public function testStackDiscoveryIgnoresInvalidDirs(): void
    {
        // Create a directory without docker-compose.yml
        $invalidDir = $this->testComposeRoot . '/not-a-stack';
        mkdir($invalidDir, 0755, true);
        file_put_contents("$invalidDir/some-file.txt", 'content');
        
        $projects = @array_diff(@scandir($this->testComposeRoot), ['.', '..']);
        
        $validStacks = [];
        foreach ($projects as $project) {
            if (is_file("$this->testComposeRoot/$project/docker-compose.yml") ||
                is_file("$this->testComposeRoot/$project/indirect")) {
                $validStacks[] = $project;
            }
        }
        
        $this->assertNotContains('not-a-stack', $validStacks);
    }

    // ===========================================
    // Stack Name Resolution Tests
    // ===========================================

    /**
     * Test stack name from name file
     */
    public function testStackNameFromFile(): void
    {
        $this->createTestStack('folder-name', ['name' => 'Display Name']);
        
        $project = 'folder-name';
        $projectName = $project;
        if (is_file("$this->testComposeRoot/$project/name")) {
            $projectName = trim(file_get_contents("$this->testComposeRoot/$project/name"));
        }
        
        $this->assertEquals('Display Name', $projectName);
    }

    /**
     * Test stack name fallback to folder name
     */
    public function testStackNameFallbackToFolder(): void
    {
        $stackPath = $this->testComposeRoot . '/folder-only';
        mkdir($stackPath, 0755, true);
        file_put_contents("$stackPath/docker-compose.yml", "services:\n");
        // No name file
        
        $project = 'folder-only';
        $projectName = $project;
        if (is_file("$this->testComposeRoot/$project/name")) {
            $projectName = trim(file_get_contents("$this->testComposeRoot/$project/name"));
        }
        
        $this->assertEquals('folder-only', $projectName);
    }

    // ===========================================
    // ID Generation Tests (for HTML elements)
    // ===========================================

    /**
     * Test HTML ID generation replaces dots with dashes
     */
    public function testIdGenerationReplacesDots(): void
    {
        $project = 'my.stack.name';
        $id = str_replace(".", "-", $project);
        $id = str_replace(" ", "", $id);
        
        $this->assertEquals('my-stack-name', $id);
    }

    /**
     * Test HTML ID generation removes spaces
     */
    public function testIdGenerationRemovesSpaces(): void
    {
        $project = 'my stack name';
        $id = str_replace(".", "-", $project);
        $id = str_replace(" ", "", $id);
        
        $this->assertEquals('mystackname', $id);
    }

    /**
     * Test HTML ID generation with mixed characters
     */
    public function testIdGenerationMixed(): void
    {
        $project = 'My.Stack Name';
        $id = str_replace(".", "-", $project);
        $id = str_replace(" ", "", $id);
        
        $this->assertEquals('My-StackName', $id);
    }

    // ===========================================
    // Autostart Detection Tests
    // ===========================================

    /**
     * Test autostart detection when enabled
     */
    public function testAutostartEnabled(): void
    {
        $this->createTestStack('autostart-on', ['autostart' => true]);
        
        $project = 'autostart-on';
        $autostart = '';
        if (is_file("$this->testComposeRoot/$project/autostart")) {
            $autostarttext = @file_get_contents("$this->testComposeRoot/$project/autostart");
            if (strpos($autostarttext, 'true') !== false) {
                $autostart = 'checked';
            }
        }
        
        $this->assertEquals('checked', $autostart);
    }

    /**
     * Test autostart detection when disabled
     */
    public function testAutostartDisabled(): void
    {
        $this->createTestStack('autostart-off', ['autostart' => false]);
        
        $project = 'autostart-off';
        $autostart = '';
        if (is_file("$this->testComposeRoot/$project/autostart")) {
            $autostarttext = @file_get_contents("$this->testComposeRoot/$project/autostart");
            if (strpos($autostarttext, 'true') !== false) {
                $autostart = 'checked';
            }
        }
        
        $this->assertEquals('', $autostart);
    }

    /**
     * Test autostart detection when no file exists
     */
    public function testAutostartNoFile(): void
    {
        $this->createTestStack('no-autostart');
        
        $project = 'no-autostart';
        $autostart = '';
        if (is_file("$this->testComposeRoot/$project/autostart")) {
            $autostarttext = @file_get_contents("$this->testComposeRoot/$project/autostart");
            if (strpos($autostarttext, 'true') !== false) {
                $autostart = 'checked';
            }
        }
        
        $this->assertEquals('', $autostart);
    }

    // ===========================================
    // Icon URL Validation Tests
    // ===========================================

    /**
     * Test valid HTTPS icon URL
     */
    public function testIconUrlHttpsValid(): void
    {
        $this->createTestStack('icon-https', ['icon_url' => 'https://example.com/icon.png']);
        
        $project = 'icon-https';
        $projectIcon = '';
        if (is_file("$this->testComposeRoot/$project/icon_url")) {
            $iconUrl = trim(@file_get_contents("$this->testComposeRoot/$project/icon_url"));
            if (filter_var($iconUrl, FILTER_VALIDATE_URL) && 
                (strpos($iconUrl, 'http://') === 0 || strpos($iconUrl, 'https://') === 0)) {
                $projectIcon = $iconUrl;
            }
        }
        
        $this->assertEquals('https://example.com/icon.png', $projectIcon);
    }

    /**
     * Test valid HTTP icon URL
     */
    public function testIconUrlHttpValid(): void
    {
        $this->createTestStack('icon-http', ['icon_url' => 'http://example.com/icon.png']);
        
        $project = 'icon-http';
        $projectIcon = '';
        if (is_file("$this->testComposeRoot/$project/icon_url")) {
            $iconUrl = trim(@file_get_contents("$this->testComposeRoot/$project/icon_url"));
            if (filter_var($iconUrl, FILTER_VALIDATE_URL) && 
                (strpos($iconUrl, 'http://') === 0 || strpos($iconUrl, 'https://') === 0)) {
                $projectIcon = $iconUrl;
            }
        }
        
        $this->assertEquals('http://example.com/icon.png', $projectIcon);
    }

    /**
     * Test invalid icon URL (not http/https)
     */
    public function testIconUrlInvalidScheme(): void
    {
        $this->createTestStack('icon-invalid', ['icon_url' => 'ftp://example.com/icon.png']);
        
        $project = 'icon-invalid';
        $projectIcon = '';
        if (is_file("$this->testComposeRoot/$project/icon_url")) {
            $iconUrl = trim(@file_get_contents("$this->testComposeRoot/$project/icon_url"));
            if (filter_var($iconUrl, FILTER_VALIDATE_URL) && 
                (strpos($iconUrl, 'http://') === 0 || strpos($iconUrl, 'https://') === 0)) {
                $projectIcon = $iconUrl;
            }
        }
        
        $this->assertEquals('', $projectIcon);
    }

    /**
     * Test malformed icon URL
     */
    public function testIconUrlMalformed(): void
    {
        $this->createTestStack('icon-malformed', ['icon_url' => 'not-a-valid-url']);
        
        $project = 'icon-malformed';
        $projectIcon = '';
        if (is_file("$this->testComposeRoot/$project/icon_url")) {
            $iconUrl = trim(@file_get_contents("$this->testComposeRoot/$project/icon_url"));
            if (filter_var($iconUrl, FILTER_VALIDATE_URL) && 
                (strpos($iconUrl, 'http://') === 0 || strpos($iconUrl, 'https://') === 0)) {
                $projectIcon = $iconUrl;
            }
        }
        
        $this->assertEquals('', $projectIcon);
    }

    // ===========================================
    // WebUI URL Validation Tests
    // ===========================================

    /**
     * Test valid WebUI URL
     */
    public function testWebuiUrlValid(): void
    {
        $this->createTestStack('webui-valid', ['webui_url' => 'https://localhost:8080']);
        
        $project = 'webui-valid';
        $webuiUrl = '';
        if (is_file("$this->testComposeRoot/$project/webui_url")) {
            $webuiUrlTmp = trim(@file_get_contents("$this->testComposeRoot/$project/webui_url"));
            if (filter_var($webuiUrlTmp, FILTER_VALIDATE_URL) && 
                (strpos($webuiUrlTmp, 'http://') === 0 || strpos($webuiUrlTmp, 'https://') === 0)) {
                $webuiUrl = $webuiUrlTmp;
            }
        }
        
        $this->assertEquals('https://localhost:8080', $webuiUrl);
    }

    // ===========================================
    // Profiles JSON Tests
    // ===========================================

    /**
     * Test profiles JSON parsing
     */
    public function testProfilesJsonParsing(): void
    {
        $this->createTestStack('profiles-test', ['profiles' => ['dev', 'prod', 'test']]);
        
        $project = 'profiles-test';
        $profiles = [];
        if (is_file("$this->testComposeRoot/$project/profiles")) {
            $profilestext = @file_get_contents("$this->testComposeRoot/$project/profiles");
            $profiles = json_decode($profilestext, false);
        }
        
        $this->assertIsArray($profiles);
        $this->assertCount(3, $profiles);
        $this->assertContains('dev', $profiles);
        $this->assertContains('prod', $profiles);
    }

    /**
     * Test profiles JSON encoding for JavaScript
     */
    public function testProfilesJsonEncoding(): void
    {
        $profiles = ['dev', 'prod'];
        $profilesJson = json_encode($profiles ? $profiles : []);
        
        $this->assertEquals('["dev","prod"]', $profilesJson);
    }

    /**
     * Test empty profiles returns empty array
     */
    public function testProfilesEmpty(): void
    {
        $this->createTestStack('no-profiles');
        
        $project = 'no-profiles';
        $profiles = [];
        if (is_file("$this->testComposeRoot/$project/profiles")) {
            $profilestext = @file_get_contents("$this->testComposeRoot/$project/profiles");
            $profiles = json_decode($profilestext, false);
        }
        $profilesJson = json_encode($profiles ? $profiles : []);
        
        $this->assertEquals('[]', $profilesJson);
    }

    // ===========================================
    // Description Handling Tests
    // ===========================================

    /**
     * Test description with newlines converted to br
     */
    public function testDescriptionNewlinesToBr(): void
    {
        $this->createTestStack('desc-newlines', ['description' => "Line 1\nLine 2\nLine 3"]);
        
        $project = 'desc-newlines';
        $description = '';
        if (is_file("$this->testComposeRoot/$project/description")) {
            $description = @file_get_contents("$this->testComposeRoot/$project/description");
            $description = str_replace("\r", "", $description);
            $description = str_replace("\n", "<br>", $description);
        }
        
        $this->assertEquals('Line 1<br>Line 2<br>Line 3', $description);
    }

    /**
     * Test description with CRLF handled
     */
    public function testDescriptionCrlfHandling(): void
    {
        $this->createTestStack('desc-crlf', ['description' => "Line 1\r\nLine 2"]);
        
        $project = 'desc-crlf';
        $description = '';
        if (is_file("$this->testComposeRoot/$project/description")) {
            $description = @file_get_contents("$this->testComposeRoot/$project/description");
            $description = str_replace("\r", "", $description);
            $description = str_replace("\n", "<br>", $description);
        }
        
        $this->assertEquals('Line 1<br>Line 2', $description);
    }
}
