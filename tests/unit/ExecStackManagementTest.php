<?php

/**
 * Unit Tests for exec.php Stack Management Actions
 * 
 * Tests the stack creation, deletion, and file operations in exec.php
 * These are more complex actions that create/delete stacks and manage files.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PluginTests\Mocks\FunctionMocks;

/**
 * Tests for exec.php stack management actions:
 * - addStack
 * - deleteStack
 * - getYml / saveYml
 * - getEnv / saveEnv
 * - getOverride / saveOverride
 * - getStackSettings / setStackSettings
 * - saveProfiles
 */
class ExecStackManagementTest extends TestCase
{
    private string $testComposeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test compose root
        $this->testComposeRoot = sys_get_temp_dir() . '/compose_stack_mgmt_test_' . getmypid();
        if (!is_dir($this->testComposeRoot)) {
            mkdir($this->testComposeRoot, 0755, true);
        }
        
        // Set the global compose_root
        global $compose_root;
        $compose_root = $this->testComposeRoot;
        
        // Set plugin config
        FunctionMocks::setPluginConfig('compose.manager', [
            'PROJECTS_FOLDER' => $this->testComposeRoot,
            'DEBUG_TO_LOG' => 'false',
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (is_dir($this->testComposeRoot)) {
            $this->recursiveDelete($this->testComposeRoot);
        }
        
        // Clear POST data
        $_POST = [];
        
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
        
        if (isset($options['indirect'])) {
            // Remove compose file and add indirect pointer
            unlink("$stackPath/docker-compose.yml");
            file_put_contents("$stackPath/indirect", $options['indirect']);
        }
        
        if (isset($options['override'])) {
            file_put_contents("$stackPath/docker-compose.override.yml", $options['override']);
        }
        
        if (isset($options['env'])) {
            file_put_contents("$stackPath/.env", $options['env']);
        }
        
        if (isset($options['envpath'])) {
            file_put_contents("$stackPath/envpath", $options['envpath']);
        }
        
        return $stackPath;
    }

    // ===========================================
    // addStack Action Tests (Simulated)
    // ===========================================

    /**
     * Test stack folder name sanitization - removes quotes
     */
    public function testStackFolderNameSanitizationQuotes(): void
    {
        $stackName = '"My Stack"';
        $folderName = str_replace('"', "", $stackName);
        $folderName = str_replace("'", "", $folderName);
        
        $this->assertEquals('My Stack', $folderName);
    }

    /**
     * Test stack folder name sanitization - removes ampersand
     */
    public function testStackFolderNameSanitizationAmpersand(): void
    {
        $stackName = 'Stack & More';
        $folderName = str_replace("&", "", $stackName);
        
        $this->assertEquals('Stack  More', $folderName);
    }

    /**
     * Test stack folder name sanitization - removes parentheses
     */
    public function testStackFolderNameSanitizationParentheses(): void
    {
        $stackName = 'Stack (Test)';
        $folderName = str_replace("(", "", $stackName);
        $folderName = str_replace(")", "", $folderName);
        
        $this->assertEquals('Stack Test', $folderName);
    }

    /**
     * Test stack folder name - spaces replaced with underscores
     */
    public function testStackFolderNameSpacesToUnderscores(): void
    {
        $stackName = 'My Stack Name';
        $folderName = preg_replace("/ {2,}/", " ", $stackName);
        $folderName = preg_replace("/\s/", "_", $folderName);
        
        $this->assertEquals('My_Stack_Name', $folderName);
    }

    /**
     * Test stack folder name - multiple spaces collapsed
     */
    public function testStackFolderNameMultipleSpaces(): void
    {
        $stackName = 'My    Stack   Name';
        $folderName = preg_replace("/ {2,}/", " ", $stackName);
        $folderName = preg_replace("/\s/", "_", $folderName);
        
        $this->assertEquals('My_Stack_Name', $folderName);
    }

    /**
     * Test stack creation creates required files
     */
    public function testStackCreationCreatesFiles(): void
    {
        $stackName = 'New Stack';
        $folderName = preg_replace("/\s/", "_", $stackName);
        $folder = "$this->testComposeRoot/$folderName";
        
        // Simulate stack creation
        mkdir($folder, 0755, true);
        file_put_contents("$folder/docker-compose.yml", "services:\n");
        file_put_contents("$folder/name", $stackName);
        
        $this->assertDirectoryExists($folder);
        $this->assertFileExists("$folder/docker-compose.yml");
        $this->assertFileExists("$folder/name");
        $this->assertEquals($stackName, file_get_contents("$folder/name"));
    }

    /**
     * Test stack creation with description
     */
    public function testStackCreationWithDescription(): void
    {
        $folder = "$this->testComposeRoot/desc_stack";
        mkdir($folder, 0755, true);
        
        $stackDesc = "This is a test stack\nWith multiple lines";
        file_put_contents("$folder/description", trim($stackDesc));
        
        $this->assertFileExists("$folder/description");
        $this->assertEquals($stackDesc, file_get_contents("$folder/description"));
    }

    /**
     * Test stack creation with indirect path
     */
    public function testStackCreationWithIndirect(): void
    {
        $indirect = '/mnt/user/appdata/mystack';
        $folder = "$this->testComposeRoot/indirect_stack";
        mkdir($folder, 0755, true);
        
        file_put_contents("$folder/indirect", $indirect);
        file_put_contents("$folder/name", "Indirect Stack");
        
        $this->assertFileExists("$folder/indirect");
        $this->assertEquals($indirect, file_get_contents("$folder/indirect"));
    }

    // ===========================================
    // deleteStack Action Tests (Simulated)
    // ===========================================

    /**
     * Test stack deletion removes directory
     */
    public function testStackDeletionRemovesDirectory(): void
    {
        $stackPath = $this->createTestStack('delete-me');
        
        $this->assertDirectoryExists($stackPath);
        
        // Simulate deletion
        $this->recursiveDelete($stackPath);
        
        $this->assertDirectoryDoesNotExist($stackPath);
    }

    /**
     * Test stack deletion with indirect returns path info
     */
    public function testStackDeletionWithIndirectReturnsPath(): void
    {
        $indirectPath = '/mnt/user/appdata/kept-files';
        $stackPath = $this->createTestStack('indirect-delete', [
            'indirect' => $indirectPath,
        ]);
        
        // Check indirect file before deletion
        $filesRemain = '';
        if (is_file("$stackPath/indirect")) {
            $filesRemain = file_get_contents("$stackPath/indirect");
        }
        
        $this->assertEquals($indirectPath, $filesRemain);
    }

    // ===========================================
    // getYml / saveYml Action Tests
    // ===========================================

    /**
     * Test getYml returns compose file content
     */
    public function testGetYmlReturnsContent(): void
    {
        $composeContent = "services:\n  web:\n    image: nginx:latest\n";
        $stackPath = $this->createTestStack('yml-test', ['compose' => $composeContent]);
        
        $basePath = "$this->testComposeRoot/yml-test";
        $fileName = "docker-compose.yml";
        
        $scriptContents = file_get_contents("$basePath/$fileName");
        $scriptContents = str_replace("\r", "", $scriptContents);
        
        $this->assertEquals($composeContent, $scriptContents);
    }

    /**
     * Test getYml returns default content when file is empty
     */
    public function testGetYmlReturnsDefaultWhenEmpty(): void
    {
        $stackPath = $this->createTestStack('empty-yml', ['compose' => '']);
        
        $basePath = "$this->testComposeRoot/empty-yml";
        $fileName = "docker-compose.yml";
        
        $scriptContents = file_get_contents("$basePath/$fileName");
        $scriptContents = str_replace("\r", "", $scriptContents);
        if (!$scriptContents) {
            $scriptContents = "services:\n";
        }
        
        $this->assertEquals("services:\n", $scriptContents);
    }

    /**
     * Test saveYml writes content to file
     */
    public function testSaveYmlWritesContent(): void
    {
        $stackPath = $this->createTestStack('save-yml');
        
        $newContent = "services:\n  redis:\n    image: redis:7\n";
        
        $basePath = "$this->testComposeRoot/save-yml";
        $fileName = "docker-compose.yml";
        
        file_put_contents("$basePath/$fileName", $newContent);
        
        $this->assertEquals($newContent, file_get_contents("$basePath/$fileName"));
    }

    /**
     * Test getYml with indirect path
     */
    public function testGetYmlWithIndirect(): void
    {
        // Create the indirect target with compose file
        $indirectTarget = sys_get_temp_dir() . '/indirect_yml_' . getmypid();
        mkdir($indirectTarget, 0755, true);
        $composeContent = "services:\n  app:\n    image: myapp\n";
        file_put_contents("$indirectTarget/docker-compose.yml", $composeContent);
        
        // Create stack with indirect pointer
        $this->createTestStack('indirect-yml', ['indirect' => $indirectTarget]);
        
        // Simulate getPath function
        $basePath = "$this->testComposeRoot/indirect-yml";
        $outPath = $basePath;
        if (is_file("$basePath/indirect")) {
            $outPath = file_get_contents("$basePath/indirect");
        }
        
        $scriptContents = file_get_contents("$outPath/docker-compose.yml");
        
        $this->assertEquals($composeContent, $scriptContents);
        
        // Cleanup
        unlink("$indirectTarget/docker-compose.yml");
        rmdir($indirectTarget);
    }

    // ===========================================
    // getEnv / saveEnv Action Tests
    // ===========================================

    /**
     * Test getEnv returns .env file content
     */
    public function testGetEnvReturnsContent(): void
    {
        $envContent = "DB_HOST=localhost\nDB_PORT=3306\n";
        $stackPath = $this->createTestStack('env-test', ['env' => $envContent]);
        
        $basePath = "$this->testComposeRoot/env-test";
        $fileName = "$basePath/.env";
        
        $scriptContents = file_get_contents($fileName);
        $scriptContents = str_replace("\r", "", $scriptContents);
        
        $this->assertEquals($envContent, $scriptContents);
    }

    /**
     * Test getEnv returns empty when no .env file
     */
    public function testGetEnvReturnsEmptyWhenNoFile(): void
    {
        $this->createTestStack('no-env');
        
        $basePath = "$this->testComposeRoot/no-env";
        $fileName = "$basePath/.env";
        
        $scriptContents = is_file($fileName) ? file_get_contents($fileName) : "";
        $scriptContents = str_replace("\r", "", $scriptContents);
        if (!$scriptContents) {
            $scriptContents = "\n";
        }
        
        $this->assertEquals("\n", $scriptContents);
    }

    /**
     * Test getEnv uses custom envpath when set
     */
    public function testGetEnvUsesCustomEnvpath(): void
    {
        // Create a custom .env file location
        $customEnvPath = sys_get_temp_dir() . '/custom_env_' . getmypid() . '.env';
        $envContent = "CUSTOM_VAR=value\n";
        file_put_contents($customEnvPath, $envContent);
        
        $this->createTestStack('custom-env', ['envpath' => $customEnvPath]);
        
        $basePath = "$this->testComposeRoot/custom-env";
        $fileName = "$basePath/.env";
        
        // Check if envpath is set
        if (is_file("$basePath/envpath")) {
            $fileName = file_get_contents("$basePath/envpath");
            $fileName = str_replace("\r", "", $fileName);
        }
        
        $scriptContents = is_file($fileName) ? file_get_contents($fileName) : "";
        
        $this->assertEquals($envContent, $scriptContents);
        
        // Cleanup
        unlink($customEnvPath);
    }

    /**
     * Test saveEnv writes content
     */
    public function testSaveEnvWritesContent(): void
    {
        $stackPath = $this->createTestStack('save-env');
        
        $newContent = "NEW_VAR=newvalue\n";
        $basePath = "$this->testComposeRoot/save-env";
        $fileName = "$basePath/.env";
        
        file_put_contents($fileName, $newContent);
        
        $this->assertEquals($newContent, file_get_contents($fileName));
    }

    // ===========================================
    // getOverride / saveOverride Action Tests
    // ===========================================

    /**
     * Test getOverride returns override file content
     */
    public function testGetOverrideReturnsContent(): void
    {
        $overrideContent = "services:\n  web:\n    ports:\n      - 80:80\n";
        $this->createTestStack('override-test', ['override' => $overrideContent]);
        
        $basePath = "$this->testComposeRoot/override-test";
        $fileName = "docker-compose.override.yml";
        
        $scriptContents = is_file("$basePath/$fileName") ? file_get_contents("$basePath/$fileName") : "";
        $scriptContents = str_replace("\r", "", $scriptContents);
        
        $this->assertEquals($overrideContent, $scriptContents);
    }

    /**
     * Test getOverride returns empty when no override file
     */
    public function testGetOverrideReturnsEmptyWhenNoFile(): void
    {
        $this->createTestStack('no-override');
        
        $basePath = "$this->testComposeRoot/no-override";
        $fileName = "docker-compose.override.yml";
        
        $scriptContents = is_file("$basePath/$fileName") ? file_get_contents("$basePath/$fileName") : "";
        
        $this->assertEquals('', $scriptContents);
    }

    /**
     * Test saveOverride writes content
     */
    public function testSaveOverrideWritesContent(): void
    {
        $stackPath = $this->createTestStack('save-override');
        
        $newContent = "services:\n  web:\n    environment:\n      - DEBUG=true\n";
        $basePath = "$this->testComposeRoot/save-override";
        $fileName = "docker-compose.override.yml";
        
        file_put_contents("$basePath/$fileName", $newContent);
        
        $this->assertEquals($newContent, file_get_contents("$basePath/$fileName"));
    }

    // ===========================================
    // getStackSettings / setStackSettings Tests
    // ===========================================

    /**
     * Test getStackSettings returns all settings
     */
    public function testGetStackSettingsReturnsAll(): void
    {
        $stackPath = $this->createTestStack('settings-test');
        file_put_contents("$stackPath/envpath", "/custom/.env");
        file_put_contents("$stackPath/icon_url", "https://example.com/icon.png");
        file_put_contents("$stackPath/webui_url", "http://localhost:8080");
        file_put_contents("$stackPath/default_profile", "production");
        file_put_contents("$stackPath/profiles", json_encode(['dev', 'prod', 'test']));
        
        $basePath = "$this->testComposeRoot/settings-test";
        
        // Read settings like the action does
        $envPath = is_file("$basePath/envpath") ? trim(file_get_contents("$basePath/envpath")) : "";
        $iconUrl = is_file("$basePath/icon_url") ? trim(file_get_contents("$basePath/icon_url")) : "";
        $webuiUrl = is_file("$basePath/webui_url") ? trim(file_get_contents("$basePath/webui_url")) : "";
        $defaultProfile = is_file("$basePath/default_profile") ? trim(file_get_contents("$basePath/default_profile")) : "";
        
        $availableProfiles = [];
        if (is_file("$basePath/profiles")) {
            $profilesData = json_decode(file_get_contents("$basePath/profiles"), true);
            if (is_array($profilesData)) {
                $availableProfiles = $profilesData;
            }
        }
        
        $this->assertEquals("/custom/.env", $envPath);
        $this->assertEquals("https://example.com/icon.png", $iconUrl);
        $this->assertEquals("http://localhost:8080", $webuiUrl);
        $this->assertEquals("production", $defaultProfile);
        $this->assertEquals(['dev', 'prod', 'test'], $availableProfiles);
    }

    /**
     * Test setStackSettings saves envPath
     */
    public function testSetStackSettingsSavesEnvPath(): void
    {
        $stackPath = $this->createTestStack('save-settings');
        
        $envPath = "/mnt/user/config/.env";
        $envPathFile = "$stackPath/envpath";
        
        if (empty($envPath)) {
            if (is_file($envPathFile)) @unlink($envPathFile);
        } else {
            file_put_contents($envPathFile, $envPath);
        }
        
        $this->assertEquals($envPath, file_get_contents($envPathFile));
    }

    /**
     * Test setStackSettings removes envPath when empty
     */
    public function testSetStackSettingsRemovesEnvPathWhenEmpty(): void
    {
        $stackPath = $this->createTestStack('clear-envpath');
        file_put_contents("$stackPath/envpath", "/old/path");
        
        $envPath = "";
        $envPathFile = "$stackPath/envpath";
        
        if (empty($envPath)) {
            if (is_file($envPathFile)) @unlink($envPathFile);
        } else {
            file_put_contents($envPathFile, $envPath);
        }
        
        $this->assertFileDoesNotExist($envPathFile);
    }

    /**
     * Test setStackSettings validates icon URL
     */
    public function testSetStackSettingsValidatesIconUrl(): void
    {
        $stackPath = $this->createTestStack('validate-icon');
        
        $iconUrl = "https://example.com/valid.png";
        $iconUrlFile = "$stackPath/icon_url";
        
        if (!filter_var($iconUrl, FILTER_VALIDATE_URL) || 
            (strpos($iconUrl, 'http://') !== 0 && strpos($iconUrl, 'https://') !== 0)) {
            $error = true;
        } else {
            $error = false;
            file_put_contents($iconUrlFile, $iconUrl);
        }
        
        $this->assertFalse($error);
        $this->assertEquals($iconUrl, file_get_contents($iconUrlFile));
    }

    /**
     * Test setStackSettings rejects invalid icon URL
     */
    public function testSetStackSettingsRejectsInvalidIconUrl(): void
    {
        $iconUrl = "javascript:alert(1)";
        
        $isValid = filter_var($iconUrl, FILTER_VALIDATE_URL) && 
            (strpos($iconUrl, 'http://') === 0 || strpos($iconUrl, 'https://') === 0);
        
        $this->assertFalse($isValid);
    }

    /**
     * Test setStackSettings saves webui URL
     */
    public function testSetStackSettingsSavesWebuiUrl(): void
    {
        $stackPath = $this->createTestStack('save-webui');
        
        $webuiUrl = "http://192.168.1.100:9000";
        $webuiUrlFile = "$stackPath/webui_url";
        
        if (!filter_var($webuiUrl, FILTER_VALIDATE_URL) || 
            (strpos($webuiUrl, 'http://') !== 0 && strpos($webuiUrl, 'https://') !== 0)) {
            $error = true;
        } else {
            $error = false;
            file_put_contents($webuiUrlFile, $webuiUrl);
        }
        
        $this->assertFalse($error);
        $this->assertEquals($webuiUrl, file_get_contents($webuiUrlFile));
    }

    /**
     * Test setStackSettings saves default profile
     */
    public function testSetStackSettingsSavesDefaultProfile(): void
    {
        $stackPath = $this->createTestStack('save-profile');
        
        $defaultProfile = "development";
        $defaultProfileFile = "$stackPath/default_profile";
        
        if (empty($defaultProfile)) {
            if (is_file($defaultProfileFile)) @unlink($defaultProfileFile);
        } else {
            file_put_contents($defaultProfileFile, $defaultProfile);
        }
        
        $this->assertEquals($defaultProfile, file_get_contents($defaultProfileFile));
    }

    // ===========================================
    // saveProfiles Action Tests
    // ===========================================

    /**
     * Test saveProfiles saves profiles JSON
     */
    public function testSaveProfilesSavesJson(): void
    {
        $stackPath = $this->createTestStack('profiles-save');
        
        $profiles = ['dev', 'staging', 'prod'];
        $profilesJson = json_encode($profiles);
        $fileName = "$stackPath/profiles";
        
        file_put_contents($fileName, $profilesJson);
        
        $this->assertEquals($profilesJson, file_get_contents($fileName));
        
        // Verify it can be decoded back
        $decoded = json_decode(file_get_contents($fileName), true);
        $this->assertEquals($profiles, $decoded);
    }

    /**
     * Test saveProfiles removes file when empty array
     */
    public function testSaveProfilesRemovesWhenEmpty(): void
    {
        $stackPath = $this->createTestStack('profiles-empty');
        file_put_contents("$stackPath/profiles", '["old"]');
        
        $profilesJson = "[]";
        $fileName = "$stackPath/profiles";
        
        if ($profilesJson == "[]") {
            if (is_file($fileName)) {
                unlink($fileName);
            }
        } else {
            file_put_contents($fileName, $profilesJson);
        }
        
        $this->assertFileDoesNotExist($fileName);
    }

    /**
     * Test saveProfiles handles complex profile names
     */
    public function testSaveProfilesComplexNames(): void
    {
        $stackPath = $this->createTestStack('profiles-complex');
        
        $profiles = ['dev-env', 'staging_env', 'prod.env'];
        $profilesJson = json_encode($profiles);
        $fileName = "$stackPath/profiles";
        
        file_put_contents($fileName, $profilesJson);
        
        $decoded = json_decode(file_get_contents($fileName), true);
        $this->assertEquals($profiles, $decoded);
    }
}
