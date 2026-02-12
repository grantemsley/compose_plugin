<?php

require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");
require_once("/usr/local/emhttp/plugins/compose.manager/php/exec_functions.php");

// CSRF token validation — Unraid stores a token in var.ini that must
// accompany every state-changing POST request.
$_var = @parse_ini_file('/var/local/emhttp/var.ini');
if ($_var && isset($_var['csrf_token'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_var['csrf_token']) {
        die(json_encode(['result' => 'error', 'message' => 'Invalid or missing CSRF token']));
    }
}

/**
 * Safely retrieve the 'script' POST parameter (stack directory name).
 * Applies basename() to prevent path traversal attacks.
 * Does NOT apply urldecode() because PHP already decodes POST data.
 *
 * @return string The sanitized script/stack directory name
 */
function getPostScript(): string {
    $script = $_POST['script'] ?? '';
    return basename(trim($script));
}

switch ($_POST['action']) {
    case 'addStack':
        #Create indirect
        $indirect = isset($_POST['stackPath']) ? trim($_POST['stackPath']) : "";
        if (!empty($indirect)) {
            if (!is_dir($indirect)) {
                exec("mkdir -p " . escapeshellarg($indirect));
                if (!is_dir($indirect)) {
                    echo json_encode(['result' => 'error', 'message' => 'Failed to create stack directory.']);
                    break;
                }
            }
        }

        #Pull stack files

        #Create stack folder
        $stackName = isset($_POST['stackName']) ? trim($_POST['stackName']) : "";
        $folderName = str_replace('"', "", $stackName);
        $folderName = str_replace("'", "", $folderName);
        $folderName = str_replace("&", "", $folderName);
        $folderName = str_replace("(", "", $folderName);
        $folderName = str_replace(")", "", $folderName);
        $folderName = preg_replace("/ {2,}/", " ", $folderName);
        $folderName = preg_replace("/\s/", "_", $folderName);
        $folder = "$compose_root/$folderName";
        while (true) {
            if (is_dir($folder)) {
                $folder .= mt_rand();
            } else {
                break;
            }
        }
        exec("mkdir -p " . escapeshellarg($folder));
        if (!is_dir($folder)) {
            echo json_encode(['result' => 'error', 'message' => 'Failed to create stack directory.']);
            break;
        }

        #Create stack files
        if (!empty($indirect)) {
            file_put_contents("$folder/indirect", $indirect);
            if (!is_file("$indirect/docker-compose.yml")) {
                file_put_contents("$indirect/docker-compose.yml", "services:\n");
            }
        } else {
            file_put_contents("$folder/docker-compose.yml", "services:\n");
        }

        // Create initial override file if it doesn't exist (for UI labels)
        $overrideFile = "$folder/docker-compose.override.yml";
        if (!is_file($overrideFile)) {
            $overrideContent = "# Override file for UI labels (icon, webui, shell)\n";
            $overrideContent .= "# This file is managed by Compose Manager\n";
            $overrideContent .= "services: {}\n";
            file_put_contents($overrideFile, $overrideContent);
        }

        file_put_contents("$folder/name", $stackName);

        // Save description if provided
        $stackDesc = isset($_POST['stackDesc']) ? trim($_POST['stackDesc']) : "";
        if (!empty($stackDesc)) {
            file_put_contents("$folder/description", trim($stackDesc));
        }

        // Return project info for opening the editor
        $projectDir = basename($folder);
        echo json_encode(['result' => 'success', 'message' => '', 'project' => $projectDir, 'projectName' => $stackName]);
        break;
    case 'deleteStack':
        $stackName = isset($_POST['stackName']) ? trim($_POST['stackName']) : "";
        if (! $stackName) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }
        $folderName = "$compose_root/$stackName";
        $filesRemain = is_file("$folderName/indirect") ? file_get_contents("$folderName/indirect") : "";
        exec("rm -rf " . escapeshellarg($folderName));
        if (!empty($filesRemain)) {
            echo json_encode(['result' => 'warning', 'message' => $filesRemain]);
        } else {
            echo json_encode(['result' => 'success', 'message' => '']);
        }
        break;
    case 'changeName':
        $script = getPostScript();
        $newName = isset($_POST['newName']) ? trim($_POST['newName']) : "";
        file_put_contents("$compose_root/$script/name", trim($newName));
        echo json_encode(['result' => 'success', 'message' => '']);
        break;
    case 'changeDesc':
        $script = getPostScript();
        $newDesc = isset($_POST['newDesc']) ? trim($_POST['newDesc']) : "";
        file_put_contents("$compose_root/$script/description", trim($newDesc));
        echo json_encode(['result' => 'success', 'message' => '']);
        break;
    case 'getDescription':
        $script = getPostScript();
        if (! $script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }
        $fileName = "$compose_root/$script/description";
        $fileContents = is_file($fileName) ? file_get_contents($fileName) : "";
        $fileContents = str_replace("\r", "", $fileContents);
        echo json_encode(['result' => 'success', 'content' => $fileContents]);
        break;
    case 'getYml':
        $script = getPostScript();
        $basePath = getPath("$compose_root/$script");
        $fileName = "docker-compose.yml";

        $scriptContents = file_get_contents("$basePath/$fileName");
        $scriptContents = str_replace("\r", "", $scriptContents);
        if (! $scriptContents) {
            $scriptContents = "services:\n";
        }
        echo json_encode(['result' => 'success', 'fileName' => "$basePath/$fileName", 'content' => $scriptContents]);
        break;
    case 'getEnv':
        $script = getPostScript();
        $basePath = getPath("$compose_root/$script");
        $fileName = "$basePath/.env";
        if (is_file("$basePath/envpath")) {
            $fileName = file_get_contents("$basePath/envpath");
            $fileName = str_replace("\r", "", $fileName);
        }

        $scriptContents = is_file("$fileName") ? file_get_contents("$fileName") : "";
        $scriptContents = str_replace("\r", "", $scriptContents);
        if (! $scriptContents) {
            $scriptContents = "\n";
        }
        echo json_encode(['result' => 'success', 'fileName' => "$fileName", 'content' => $scriptContents]);
        break;
    case 'getOverride':
        $script = getPostScript();
        $basePath = "$compose_root/$script";
        $fileName = "docker-compose.override.yml";

        $scriptContents = is_file("$basePath/$fileName") ? file_get_contents("$basePath/$fileName") : "";
        $scriptContents = str_replace("\r", "", $scriptContents);
        if (! $scriptContents) {
            $scriptContents = "";
        }
        echo json_encode(['result' => 'success', 'fileName' => "$basePath/$fileName", 'content' => $scriptContents]);
        break;
    case 'saveYml':
        $script = getPostScript();
        $scriptContents = isset($_POST['scriptContents']) ? $_POST['scriptContents'] : "";
        $basePath = getPath("$compose_root/$script");
        $fileName = "docker-compose.yml";

        file_put_contents("$basePath/$fileName", $scriptContents);
        echo "$basePath/$fileName saved";
        break;
    case 'saveEnv':
        $script = getPostScript();
        $scriptContents = isset($_POST['scriptContents']) ? $_POST['scriptContents'] : "";
        $basePath = getPath("$compose_root/$script");
        $fileName = "$basePath/.env";
        if (is_file("$basePath/envpath")) {
            $fileName = file_get_contents("$basePath/envpath");
            $fileName = str_replace("\r", "", $fileName);
        }

        file_put_contents("$fileName", $scriptContents);
        echo "$fileName saved";
        break;
    case 'saveOverride':
        $script = getPostScript();
        $scriptContents = isset($_POST['scriptContents']) ? $_POST['scriptContents'] : "";
        $basePath = "$compose_root/$script";
        $fileName = "docker-compose.override.yml";

        file_put_contents("$basePath/$fileName", $scriptContents);
        echo "$basePath/$fileName saved";
        break;
    case 'updateAutostart':
        $script = getPostScript();
        if (! $script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }
        $autostart = isset($_POST['autostart']) ? trim($_POST['autostart']) : "false";
        $fileName = "$compose_root/$script/autostart";
        if (is_file($fileName)) {
            exec("rm " . escapeshellarg($fileName));
        }
        file_put_contents($fileName, $autostart);
        echo json_encode(['result' => 'success', 'message' => '']);
        break;

    case 'runPatch':
        $cmd = isset($_POST['cmd']) ? $_POST['cmd'] : 'apply';
        if (!in_array($cmd, ['apply', 'remove'])) {
            echo json_encode(['result' => 'error', 'message' => 'Invalid command']);
            break;
        }
        $script = "$plugin_root/scripts/patch.sh";
        // Quote each argument to preserve spaces and special characters and avoid the fragility of escapeshellcmd()
        $fullcmd = escapeshellarg($script) . ' ' . escapeshellarg($cmd) . ' ' . escapeshellarg('--verbose') . ' 2>&1';
        exec($fullcmd, $output, $rc);
        // Save a copy to plugin log file
        $logfile = "/boot/config/plugins/compose.manager/patch_last_run.log";
        $ts = date('c');
        $entry = "[{$ts}] runPatch {$cmd} exit={$rc}\n" . implode("\n", $output) . "\n\n";
        @file_put_contents($logfile, $entry, FILE_APPEND);
        // If debug logging enabled, send to syslog
        $cfg = parse_plugin_cfg($sName);
        if ((($cfg['DEBUG_TO_LOG'] ?? 'false') == 'true')) {
            openlog("compose.manager", LOG_PID, LOG_USER);
            foreach ($output as $line) {
                syslog(LOG_DEBUG, "[patch.sh {$cmd}] " . $line);
            }
            closelog();
        }
        echo json_encode(['result' => $rc === 0 ? 'success' : 'error', 'output' => implode("\n", $output), 'rc' => $rc]);
        break;

    case 'clearUpdateCache':
        // Clear the compose manager update status cache
        $composeUpdateStatusFile = "/boot/config/plugins/compose.manager/update-status.json";
        if (is_file($composeUpdateStatusFile)) {
            unlink($composeUpdateStatusFile);
        }
        // Also clear entries from Unraid's update status that were created by compose manager
        // by removing entries that don't correspond to running Docker containers
        $unraidUpdateStatusFile = "/var/lib/docker/unraid-update-status.json";
        if (is_file($unraidUpdateStatusFile)) {
            require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");
            $DockerClient = new DockerClient();
            $runningImages = [];
            foreach ($DockerClient->getDockerContainers() as $ct) {
                $img = $ct['Image'] ?? '';
                if ($img) {
                    $runningImages[DockerUtil::ensureImageTag($img)] = true;
                }
            }
            $updateStatus = DockerUtil::loadJSON($unraidUpdateStatusFile);
            $cleaned = [];
            foreach ($updateStatus as $key => $value) {
                if (isset($runningImages[$key])) {
                    $cleaned[$key] = $value;
                }
            }
            DockerUtil::saveJSON($unraidUpdateStatusFile, $cleaned);
        }
        echo json_encode(['result' => 'success', 'message' => 'Update cache cleared']);
        break;
    case 'setEnvPath':
        $script = getPostScript();
        if (! $script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }
        $fileContent = isset($_POST['envPath']) ? trim($_POST['envPath']) : "";
        $fileName = "$compose_root/$script/envpath";
        if (is_file($fileName)) {
            exec("rm " . escapeshellarg($fileName));
        }
        if (isset($fileContent) && !empty($fileContent)) {
            file_put_contents($fileName, $fileContent);
        }
        echo json_encode(['result' => 'success', 'message' => '']);
        break;
    case 'getEnvPath':
        $script = getPostScript();
        if (! $script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }
        $fileName = "$compose_root/$script/envpath";
        $fileContents = is_file("$fileName") ? file_get_contents("$fileName") : "";
        $fileContents = str_replace("\r", "", $fileContents);
        if (! $fileContents) {
            $fileContents = "";
        }
        echo json_encode(['result' => 'success', 'fileName' => "$fileName", 'content' => $fileContents]);
        break;
    case 'getStackSettings':
        $script = getPostScript();
        if (! $script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }
        // Get env path
        $envPathFile = "$compose_root/$script/envpath";
        $envPath = is_file($envPathFile) ? trim(file_get_contents($envPathFile)) : "";

        // Get icon URL
        $iconUrlFile = "$compose_root/$script/icon_url";
        $iconUrl = is_file($iconUrlFile) ? trim(file_get_contents($iconUrlFile)) : "";

        // Get WebUI URL (stack-level)
        $webuiUrlFile = "$compose_root/$script/webui_url";
        $webuiUrl = is_file($webuiUrlFile) ? trim(file_get_contents($webuiUrlFile)) : "";

        // Get default profile
        $defaultProfileFile = "$compose_root/$script/default_profile";
        $defaultProfile = is_file($defaultProfileFile) ? trim(file_get_contents($defaultProfileFile)) : "";

        // Get available profiles from the profiles file
        $profilesFile = "$compose_root/$script/profiles";
        $availableProfiles = [];
        if (is_file($profilesFile)) {
            $profilesData = json_decode(file_get_contents($profilesFile), true);
            if (is_array($profilesData)) {
                $availableProfiles = $profilesData;
            }
        }

        echo json_encode([
            'result' => 'success',
            'envPath' => $envPath,
            'iconUrl' => $iconUrl,
            'webuiUrl' => $webuiUrl,
            'defaultProfile' => $defaultProfile,
            'availableProfiles' => $availableProfiles
        ]);
        break;
    case 'setStackSettings':
        $script = getPostScript();
        if (! $script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }

        // Set env path
        $envPath = isset($_POST['envPath']) ? trim($_POST['envPath']) : "";
        $envPathFile = "$compose_root/$script/envpath";
        if (empty($envPath)) {
            if (is_file($envPathFile)) @unlink($envPathFile);
        } else {
            file_put_contents($envPathFile, $envPath);
        }

        // Set icon URL
        $iconUrl = isset($_POST['iconUrl']) ? trim($_POST['iconUrl']) : "";
        $iconUrlFile = "$compose_root/$script/icon_url";
        if (empty($iconUrl)) {
            if (is_file($iconUrlFile)) @unlink($iconUrlFile);
        } else {
            // Validate URL
            if (!filter_var($iconUrl, FILTER_VALIDATE_URL) || (strpos($iconUrl, 'http://') !== 0 && strpos($iconUrl, 'https://') !== 0)) {
                echo json_encode(['result' => 'error', 'message' => 'Invalid icon URL. Must be http:// or https://']);
                break;
            }
            file_put_contents($iconUrlFile, $iconUrl);
        }

        // Set WebUI URL (stack-level)
        $webuiUrl = isset($_POST['webuiUrl']) ? trim($_POST['webuiUrl']) : "";
        $webuiUrlFile = "$compose_root/$script/webui_url";
        if (empty($webuiUrl)) {
            if (is_file($webuiUrlFile)) @unlink($webuiUrlFile);
        } else {
            // Validate URL
            if (!filter_var($webuiUrl, FILTER_VALIDATE_URL) || (strpos($webuiUrl, 'http://') !== 0 && strpos($webuiUrl, 'https://') !== 0)) {
                echo json_encode(['result' => 'error', 'message' => 'Invalid WebUI URL. Must be http:// or https://']);
                break;
            }
            file_put_contents($webuiUrlFile, $webuiUrl);
        }

        // Set default profile
        $defaultProfile = isset($_POST['defaultProfile']) ? trim($_POST['defaultProfile']) : "";
        $defaultProfileFile = "$compose_root/$script/default_profile";
        if (empty($defaultProfile)) {
            if (is_file($defaultProfileFile)) @unlink($defaultProfileFile);
        } else {
            file_put_contents($defaultProfileFile, $defaultProfile);
        }

        echo json_encode(['result' => 'success', 'message' => 'Settings saved']);
        break;
    case 'saveProfiles':
        $script = getPostScript();
        $scriptContents = isset($_POST['scriptContents']) ? $_POST['scriptContents'] : "";
        $basePath = "$compose_root/$script";
        $fileName = "$basePath/profiles";

        if ($scriptContents == "[]") {
            if (is_file($fileName)) {
                exec("rm " . escapeshellarg($fileName));
                echo json_encode(['result' => 'success', 'message' => "$fileName deleted"]);
            }

            echo json_encode(['result' => 'success', 'message' => '']);
            break;
        }

        file_put_contents("$fileName", $scriptContents);
        echo json_encode(['result' => 'success', 'message' => "$fileName saved"]);
        break;
    case 'getStackContainers':
        $script = getPostScript();
        if (! $script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }

        // Get the project name (sanitized)
        $projectName = $script;
        if (is_file("$compose_root/$script/name")) {
            $projectName = trim(file_get_contents("$compose_root/$script/name"));
        }
        $projectName = sanitizeStr($projectName);

        // Get containers for this compose project using docker compose ps
        $basePath = getPath("$compose_root/$script");
        $composeFile = "$basePath/docker-compose.yml";
        $overrideFile = "$compose_root/$script/docker-compose.override.yml";

        $files = "-f " . escapeshellarg($composeFile);
        if (is_file($overrideFile)) {
            $files .= " -f " . escapeshellarg($overrideFile);
        }

        $envFile = "";
        if (is_file("$compose_root/$script/envpath")) {
            $envPath = trim(file_get_contents("$compose_root/$script/envpath"));
            if (is_file($envPath)) {
                $envFile = "--env-file " . escapeshellarg($envPath);
            }
        }

        // Get container details in JSON format
        // Include --all so exited/stopped containers are returned as well
        $cmd = "docker compose $files $envFile -p " . escapeshellarg($projectName) . " ps --all --format json 2>/dev/null";
        $output = shell_exec($cmd);

        // Cache network drivers for resolving network types (bridge vs macvlan/ipvlan)
        $networkDrivers = [];
        $netListOutput = shell_exec("docker network ls --format '{{.Name}}\t{{.Driver}}' 2>/dev/null");
        if ($netListOutput) {
            foreach (explode("\n", trim($netListOutput)) as $netLine) {
                $parts = explode("\t", $netLine);
                if (count($parts) === 2) {
                    $networkDrivers[$parts[0]] = $parts[1];
                }
            }
        }

        // Get host IP for WebUI resolution (same approach as Unraid's DockerUtil::host())
        $hostIP = '';
        foreach (['br0', 'bond0', 'eth0'] as $iface) {
            $hostIP = trim(shell_exec("ip -br -4 addr show $iface scope global 2>/dev/null | sed -r 's/\/[0-9]+//g' | awk '{print \$3;exit}'"));
            if ($hostIP) break;
        }

        $containers = [];
        // Load update status once before the loop (static data, doesn't change per-container)
        $updateStatusFile = "/var/lib/docker/unraid-update-status.json";
        $updateStatus = [];
        if (is_file($updateStatusFile)) {
            $updateStatus = json_decode(file_get_contents($updateStatusFile), true) ?: [];
        }

        if ($output) {
            // docker compose ps --format json outputs one JSON object per line
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                if (!empty($line)) {
                    $container = json_decode($line, true);
                    if ($container) {
                        // Get additional details using docker inspect
                        $containerName = $container['Name'] ?? '';
                        if ($containerName) {
                            $inspectCmd = "docker inspect " . escapeshellarg($containerName) . " --format '{{json .}}' 2>/dev/null";
                            $inspectOutput = shell_exec($inspectCmd);
                            if ($inspectOutput) {
                                $inspect = json_decode($inspectOutput, true);
                                if ($inspect) {
                                    // Extract useful info from inspect
                                    $container['Image'] = $inspect['Config']['Image'] ?? '';
                                    $container['Created'] = $inspect['Created'] ?? '';
                                    $container['StartedAt'] = $inspect['State']['StartedAt'] ?? '';

                                    // Get ports (raw bindings - IP resolved below after network detection)
                                    $ports = [];
                                    $portBindings = $inspect['HostConfig']['PortBindings'] ?? [];
                                    foreach ($portBindings as $containerPort => $bindings) {
                                        if ($bindings) {
                                            foreach ($bindings as $binding) {
                                                $hostPort = $binding['HostPort'] ?? '';
                                                if ($hostPort) {
                                                    $ports[] = ['hostPort' => $hostPort, 'containerPort' => $containerPort];
                                                }
                                            }
                                        }
                                    }

                                    // Get volumes
                                    $volumes = [];
                                    $mounts = $inspect['Mounts'] ?? [];
                                    foreach ($mounts as $mount) {
                                        $src = $mount['Source'] ?? '';
                                        $dst = $mount['Destination'] ?? '';
                                        $type = $mount['Type'] ?? 'bind';
                                        if ($src && $dst) {
                                            $volumes[] = ['source' => $src, 'destination' => $dst, 'type' => $type];
                                        }
                                    }
                                    $container['Volumes'] = $volumes;

                                    // Get network info (include driver for IP resolution)
                                    $networks = [];
                                    $networkSettings = $inspect['NetworkSettings']['Networks'] ?? [];
                                    foreach ($networkSettings as $netName => $netConfig) {
                                        $networks[] = [
                                            'name' => $netName,
                                            'ip' => $netConfig['IPAddress'] ?? '',
                                            'driver' => $networkDrivers[$netName] ?? ''
                                        ];
                                    }
                                    $container['Networks'] = $networks;

                                    // Get labels for WebUI
                                    $labels = $inspect['Config']['Labels'] ?? [];
                                    $webUITemplate = $labels[$docker_label_webui] ?? '';
                                    $container['Icon'] = $labels[$docker_label_icon] ?? '';
                                    $container['Shell'] = $labels[$docker_label_shell] ?? '/bin/bash';

                                    // Resolve WebUI URL server-side (matching Unraid's DockerClient logic)
                                    // Determine the NetworkMode
                                    $networkMode = $inspect['HostConfig']['NetworkMode'] ?? 'bridge';
                                    // For "container:xxx" mode, strip to just the network portion
                                    if (strpos($networkMode, ':') !== false) {
                                        [$networkMode] = explode(':', $networkMode);
                                    }

                                    $container['WebUI'] = '';
                                    // Resolve IP — Unraid logic:
                                    // host mode → host IP
                                    // macvlan/ipvlan → container IP
                                    // bridge (with port mappings) → host IP
                                    $resolvedIP = $hostIP;
                                    if ($networkMode === 'host') {
                                        $resolvedIP = $hostIP;
                                    } elseif (
                                        isset($networkDrivers[$networkMode]) &&
                                        in_array($networkDrivers[$networkMode], ['macvlan', 'ipvlan'])
                                    ) {
                                        // Use container's own routable IP
                                        $firstNet = reset($networkSettings);
                                        $containerIP = $firstNet['IPAddress'] ?? '';
                                        if ($containerIP) $resolvedIP = $containerIP;
                                    }
                                    // For bridge/overlay/other → use host IP (default)

                                    // Build port strings with resolved IP
                                    $portStrings = [];
                                    foreach ($ports as $p) {
                                        $lanIp = $resolvedIP ?: $hostIP;
                                        $portStrings[] = "$lanIp:{$p['hostPort']}->{$p['containerPort']}";
                                    }
                                    $container['Ports'] = $portStrings;

                                    if (!empty($webUITemplate) && $hostIP) {
                                        $resolvedURL = preg_replace('%\[IP\]%i', $resolvedIP, $webUITemplate);

                                        // Resolve [PORT:xxxx] — find host-mapped port for the container port
                                        if (preg_match('%\[PORT:(\d+)\]%i', $resolvedURL, $portMatch)) {
                                            $configPort = $portMatch[1];
                                            // Look through port bindings for matching container port
                                            foreach ($portBindings as $ctPort => $bindings) {
                                                // $ctPort is like "8080/tcp"
                                                $ctPortNum = preg_replace('/\/.*$/', '', $ctPort);
                                                if ($ctPortNum === $configPort && $bindings) {
                                                    $hostPort = $bindings[0]['HostPort'] ?? '';
                                                    if ($hostPort) {
                                                        $configPort = $hostPort;
                                                    }
                                                    break;
                                                }
                                            }
                                            $resolvedURL = preg_replace('%\[PORT:\d+\]%i', $configPort, $resolvedURL);
                                        }
                                        $container['WebUI'] = $resolvedURL;
                                    }

                                    // Get update status from saved status file (read once before loop)
                                    $imageName = $container['Image'];
                                    // Ensure image has a tag for lookup
                                    if (strpos($imageName, ':') === false) {
                                        $imageName .= ':latest';
                                    }
                                    // Also try without registry prefix
                                    $imageNameShort = preg_replace('/^[^\/]+\//', '', $imageName);

                                    $container['UpdateStatus'] = 'unknown';
                                    $container['LocalSha'] = '';
                                    $container['RemoteSha'] = '';

                                    // Check both full name and short name
                                    $checkNames = [$imageName, $imageNameShort];
                                    foreach ($updateStatus as $key => $status) {
                                        foreach ($checkNames as $checkName) {
                                            if ($key === $checkName || strpos($key, $checkName) !== false || strpos($checkName, $key) !== false) {
                                                // Strip sha256: prefix before truncating to 12 hex chars
                                                $localRaw = $status['local'] ?? '';
                                                $remoteRaw = $status['remote'] ?? '';
                                                $container['LocalSha'] = substr(str_replace('sha256:', '', $localRaw), 0, 8);
                                                $container['RemoteSha'] = substr(str_replace('sha256:', '', $remoteRaw), 0, 8);
                                                if (!empty($status['local']) && !empty($status['remote'])) {
                                                    $container['UpdateStatus'] = ($status['local'] === $status['remote']) ? 'up-to-date' : 'update-available';
                                                }
                                                break 2;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        $containers[] = $container;
                    }
                }
            }
        }

        echo json_encode(['result' => 'success', 'containers' => $containers, 'projectName' => $projectName]);
        break;
    case 'containerAction':
        $containerName = isset($_POST['container']) ? trim($_POST['container']) : "";
        $containerAction = isset($_POST['containerAction']) ? trim($_POST['containerAction']) : "";

        if (! $containerName || ! $containerAction) {
            echo json_encode(['result' => 'error', 'message' => 'Container or action not specified.']);
            break;
        }

        $allowedActions = ['start', 'stop', 'restart', 'pause', 'unpause'];
        if (!in_array($containerAction, $allowedActions)) {
            echo json_encode(['result' => 'error', 'message' => 'Invalid action.']);
            break;
        }

        $cmd = "docker " . escapeshellarg($containerAction) . " " . escapeshellarg($containerName) . " 2>&1";
        $output = shell_exec($cmd);

        echo json_encode(['result' => 'success', 'message' => trim($output)]);
        break;
    case 'checkStackUpdates':
        // Check for updates for all containers in a compose stack
        $script = getPostScript();
        if (! $script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }

        // Include Docker manager classes for update checking
        require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");

        // Get the project name (sanitized)
        $projectName = $script;
        if (is_file("$compose_root/$script/name")) {
            $projectName = trim(file_get_contents("$compose_root/$script/name"));
        }
        $projectName = sanitizeStr($projectName);

        // Get containers for this compose project
        $basePath = getPath("$compose_root/$script");
        $composeFile = "$basePath/docker-compose.yml";
        $overrideFile = "$compose_root/$script/docker-compose.override.yml";

        $files = "-f " . escapeshellarg($composeFile);
        if (is_file($overrideFile)) {
            $files .= " -f " . escapeshellarg($overrideFile);
        }

        $envFile = "";
        if (is_file("$compose_root/$script/envpath")) {
            $envPath = trim(file_get_contents("$compose_root/$script/envpath"));
            if (is_file($envPath)) {
                $envFile = "--env-file " . escapeshellarg($envPath);
            }
        }

        // Get container images
        // Include --all to ensure non-running containers are considered for update checks
        $cmd = "docker compose $files $envFile -p " . escapeshellarg($projectName) . " ps --all --format json 2>/dev/null";
        $output = shell_exec($cmd);

        $updateResults = [];
        $DockerUpdate = new DockerUpdate();

        // Load the update status file to get SHA values
        $dockerManPaths = [
            'update-status' => "/var/lib/docker/unraid-update-status.json"
        ];

        if ($output) {
            $lines = explode("\n", trim($output));

            // Load the update status data ONCE before the loop instead of per-container
            $updateStatusData = DockerUtil::loadJSON($dockerManPaths['update-status']);
            $statusDirty = false;

            // First pass: clear cached local SHAs for all images that need checking
            foreach ($lines as $line) {
                if (!empty($line)) {
                    $container = json_decode($line, true);
                    if ($container) {
                        $image = $container['Image'] ?? '';
                        if ($image) {
                            $image = normalizeImageForUpdateCheck($image);
                            if (isset($updateStatusData[$image])) {
                                $updateStatusData[$image]['local'] = null;
                                $statusDirty = true;
                            }
                        }
                    }
                }
            }

            // Save once after clearing all cached SHAs
            if ($statusDirty) {
                DockerUtil::saveJSON($dockerManPaths['update-status'], $updateStatusData);
            }

            // Second pass: check updates and collect results
            foreach ($lines as $line) {
                if (!empty($line)) {
                    $container = json_decode($line, true);
                    if ($container) {
                        $containerName = $container['Name'] ?? '';
                        $image = $container['Image'] ?? '';

                        if ($containerName && $image) {
                            // Normalize image name (strip docker.io/ prefix, @sha256: digest, add library/ for official images)
                            $image = normalizeImageForUpdateCheck($image);

                            // Check update status using Unraid's DockerUpdate class
                            $DockerUpdate->reloadUpdateStatus($image);
                            $updateStatus = $DockerUpdate->getUpdateStatus($image);

                            // Re-read status data (may have been updated by reloadUpdateStatus)
                            $updateStatusData = DockerUtil::loadJSON($dockerManPaths['update-status']);
                            $localSha = '';
                            $remoteSha = '';

                            if (isset($updateStatusData[$image])) {
                                $localSha = $updateStatusData[$image]['local'] ?? '';
                                $remoteSha = $updateStatusData[$image]['remote'] ?? '';
                                // Shorten SHA for display (first 12 chars after sha256:)
                                if ($localSha && strpos($localSha, 'sha256:') === 0) {
                                    $localSha = substr($localSha, 7, 12);
                                }
                                if ($remoteSha && strpos($remoteSha, 'sha256:') === 0) {
                                    $remoteSha = substr($remoteSha, 7, 12);
                                }
                            }

                            // null = unknown, true = up to date, false = update available
                            $hasUpdate = ($updateStatus === false);
                            $statusText = ($updateStatus === null) ? 'unknown' : ($updateStatus ? 'up-to-date' : 'update-available');

                            $updateResults[] = [
                                'container' => $containerName,
                                'image' => $image,
                                'hasUpdate' => $hasUpdate,
                                'status' => $statusText,
                                'localSha' => $localSha,
                                'remoteSha' => $remoteSha
                            ];
                        }
                    }
                }
            }
        }

        echo json_encode(['result' => 'success', 'updates' => $updateResults, 'projectName' => $projectName]);

        // Save the update status for this stack
        $composeUpdateStatusFile = "/boot/config/plugins/compose.manager/update-status.json";
        $savedStatus = [];
        if (is_file($composeUpdateStatusFile)) {
            $savedStatus = json_decode(file_get_contents($composeUpdateStatusFile), true) ?: [];
        }
        $savedStatus[$script] = [
            'projectName' => $projectName,
            'hasUpdate' => count(array_filter($updateResults, function ($r) {
                return $r['hasUpdate'];
            })) > 0,
            'containers' => $updateResults,
            'lastChecked' => time()
        ];
        file_put_contents($composeUpdateStatusFile, json_encode($savedStatus, JSON_PRETTY_PRINT));
        break;
    case 'checkAllStacksUpdates':
        // Check for updates for all compose stacks
        require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");

        $allUpdates = [];
        $DockerUpdate = new DockerUpdate();

        // Path to update status file
        $dockerManPaths = [
            'update-status' => "/var/lib/docker/unraid-update-status.json"
        ];

        // Iterate through all stacks
        $stacks = glob("$compose_root/*/docker-compose.yml", GLOB_NOSORT);
        $indirectStacks = glob("$compose_root/*/indirect", GLOB_NOSORT);

        foreach ($indirectStacks as $indirect) {
            $indirectPath = file_get_contents($indirect);
            if (is_file("$indirectPath/docker-compose.yml")) {
                $stacks[] = "$indirectPath/docker-compose.yml";
            }
        }

        foreach ($stacks as $composeFile) {
            $stackDir = dirname($composeFile);
            $stackName = basename(dirname($composeFile));

            // For indirect stacks, find the actual stack folder
            foreach (glob("$compose_root/*/indirect", GLOB_NOSORT) as $indirect) {
                if (trim(file_get_contents($indirect)) == $stackDir) {
                    $stackName = basename(dirname($indirect));
                    break;
                }
            }

            // Get project name
            $projectName = $stackName;
            if (is_file("$compose_root/$stackName/name")) {
                $projectName = trim(file_get_contents("$compose_root/$stackName/name"));
            }
            $projectName = sanitizeStr($projectName);

            // Get containers
            $files = "-f " . escapeshellarg($composeFile);
            $overrideFile = "$compose_root/$stackName/docker-compose.override.yml";
            if (is_file($overrideFile)) {
                $files .= " -f " . escapeshellarg($overrideFile);
            }

            // Include --all so we can detect stacks that have stopped containers
            $cmd = "docker compose $files -p " . escapeshellarg($projectName) . " ps --all --format json 2>/dev/null";
            $output = shell_exec($cmd);

            $stackUpdates = [];
            $hasStackUpdate = false;
            $isRunning = false;

            if ($output) {
                $lines = explode("\n", trim($output));
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $container = json_decode($line, true);
                        if ($container) {
                            $containerName = $container['Name'] ?? '';
                            $image = $container['Image'] ?? '';
                            $state = $container['State'] ?? '';

                            // Check if any container is running
                            if ($state === 'running') {
                                $isRunning = true;
                            }

                            // Only check updates for running containers
                            if ($containerName && $image && $state === 'running') {
                                // Normalize image name (strip docker.io/ prefix, @sha256: digest, add library/ for official images)
                                $image = normalizeImageForUpdateCheck($image);

                                // Clear cached local SHA to force re-inspection of the actual image
                                // This is needed because Unraid's reloadUpdateStatus uses cached values
                                // which can be stale after docker compose pull
                                $updateStatusData = DockerUtil::loadJSON($dockerManPaths['update-status']);
                                if (isset($updateStatusData[$image])) {
                                    // Clear the local SHA to force fresh inspection
                                    $updateStatusData[$image]['local'] = null;
                                    DockerUtil::saveJSON($dockerManPaths['update-status'], $updateStatusData);
                                }

                                $DockerUpdate->reloadUpdateStatus($image);
                                $updateStatus = $DockerUpdate->getUpdateStatus($image);

                                // Get SHA values from the status file
                                $updateStatusData = DockerUtil::loadJSON($dockerManPaths['update-status']);
                                $localSha = '';
                                $remoteSha = '';

                                if (isset($updateStatusData[$image])) {
                                    $localSha = $updateStatusData[$image]['local'] ?? '';
                                    $remoteSha = $updateStatusData[$image]['remote'] ?? '';
                                    // Shorten SHA for display (first 12 chars after sha256:)
                                    if ($localSha && strpos($localSha, 'sha256:') === 0) {
                                        $localSha = substr($localSha, 7, 12);
                                    }
                                    if ($remoteSha && strpos($remoteSha, 'sha256:') === 0) {
                                        $remoteSha = substr($remoteSha, 7, 12);
                                    }
                                }

                                $hasUpdate = ($updateStatus === false);
                                if ($hasUpdate) $hasStackUpdate = true;

                                $stackUpdates[] = [
                                    'container' => $containerName,
                                    'image' => $image,
                                    'hasUpdate' => $hasUpdate,
                                    'status' => ($updateStatus === null) ? 'unknown' : ($updateStatus ? 'up-to-date' : 'update-available'),
                                    'localSha' => $localSha,
                                    'remoteSha' => $remoteSha
                                ];
                            }
                        }
                    }
                }
            }

            $allUpdates[$stackName] = [
                'projectName' => $projectName,
                'hasUpdate' => $hasStackUpdate,
                'isRunning' => $isRunning,
                'containers' => $stackUpdates
            ];
        }

        // Save the update status for all stacks
        $composeUpdateStatusFile = "/boot/config/plugins/compose.manager/update-status.json";
        $savedStatus = $allUpdates;
        foreach ($savedStatus as $stackKey => &$stackData) {
            $stackData['lastChecked'] = time();
        }
        file_put_contents($composeUpdateStatusFile, json_encode($savedStatus, JSON_PRETTY_PRINT));

        echo json_encode(['result' => 'success', 'stacks' => $allUpdates]);
        break;
    case 'getSavedUpdateStatus':
        // Load saved update status from file
        $composeUpdateStatusFile = "/boot/config/plugins/compose.manager/update-status.json";
        if (is_file($composeUpdateStatusFile)) {
            $savedStatus = json_decode(file_get_contents($composeUpdateStatusFile), true);
            if ($savedStatus) {
                echo json_encode(['result' => 'success', 'stacks' => $savedStatus]);
            } else {
                echo json_encode(['result' => 'success', 'stacks' => []]);
            }
        } else {
            echo json_encode(['result' => 'success', 'stacks' => []]);
        }
        break;
    case 'getLogs':
        // Get compose-related log entries from syslog
        $lines = isset($_POST['lines']) ? intval($_POST['lines']) : 100;
        $filter = isset($_POST['filter']) ? trim($_POST['filter']) : '';

        // Sanitize inputs
        $lines = max(10, min(5000, $lines)); // Limit between 10 and 5000 lines

        // Build grep command to find compose-related entries
        // Look for: compose, docker compose, compose.manager entries
        $grepPattern = 'compose\\|docker compose\\|compose.manager\\|compose.sh';

        // Read from syslog
        $syslogFile = '/var/log/syslog';
        if (!is_file($syslogFile)) {
            $syslogFile = '/var/log/messages';
        }

        if (!is_file($syslogFile)) {
            echo json_encode(['result' => 'error', 'message' => 'Syslog file not found']);
            break;
        }

        // Use grep to find relevant entries and tail to limit output
        $cmd = "grep -i " . escapeshellarg($grepPattern) . " " . escapeshellarg($syslogFile);

        // Apply additional filter if provided
        if (!empty($filter)) {
            $cmd .= " | grep -i " . escapeshellarg($filter);
        }

        $cmd .= " | tail -n " . escapeshellarg($lines);

        $output = [];
        exec($cmd, $output, $returnCode);

        // Parse log entries
        $logs = [];
        foreach ($output as $line) {
            // Parse syslog format: "Mon DD HH:MM:SS hostname source[pid]: message"
            // or: "YYYY-MM-DD HH:MM:SS hostname source[pid]: message"
            if (preg_match('/^(\w+\s+\d+\s+\d+:\d+:\d+|\d{4}-\d{2}-\d{2}\s+\d+:\d+:\d+)\s+(\S+)\s+([^:]+):\s*(.*)$/', $line, $matches)) {
                $logs[] = [
                    'timestamp' => $matches[1],
                    'hostname' => $matches[2],
                    'source' => trim($matches[3]),
                    'message' => $matches[4]
                ];
            } else {
                // Fallback for lines that don't match expected format
                $logs[] = [
                    'timestamp' => '',
                    'hostname' => '',
                    'source' => 'unknown',
                    'message' => $line
                ];
            }
        }

        echo json_encode([
            'result' => 'success',
            'logs' => $logs,
            'count' => count($logs)
        ]);
        break;

    case 'checkStackLock':
        // Check if a stack is currently locked (operation in progress)
        $script = getPostScript();
        if (! $script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }

        $lockInfo = isStackLocked($script);
        if ($lockInfo) {
            echo json_encode([
                'result' => 'success',
                'locked' => true,
                'info' => $lockInfo
            ]);
        } else {
            echo json_encode([
                'result' => 'success',
                'locked' => false
            ]);
        }
        break;

    case 'getStackResult':
        // Get the last operation result for a stack
        $script = getPostScript();
        if (! $script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }

        $stackPath = "$compose_root/$script";
        $lastResult = getStackLastResult($stackPath);

        echo json_encode([
            'result' => 'success',
            'lastResult' => $lastResult
        ]);
        break;

    case 'markStackForRecheck':
        // Mark one or more stacks for recheck after update
        // This persists across page reloads so the recheck happens even if page refreshes
        $stacks = isset($_POST['stacks']) ? $_POST['stacks'] : "";
        if (is_string($stacks)) {
            $stacks = json_decode($stacks, true);
        }
        if (!is_array($stacks) || empty($stacks)) {
            echo json_encode(['result' => 'error', 'message' => 'No stacks specified.']);
            break;
        }

        $pendingRecheckFile = "/boot/config/plugins/compose.manager/pending-recheck.json";
        $pending = [];
        if (is_file($pendingRecheckFile)) {
            $pending = json_decode(file_get_contents($pendingRecheckFile), true) ?: [];
        }

        // Add stacks to pending list with timestamp
        foreach ($stacks as $stackName) {
            $pending[$stackName] = time();
        }

        file_put_contents($pendingRecheckFile, json_encode($pending, JSON_PRETTY_PRINT));
        echo json_encode(['result' => 'success', 'pending' => array_keys($pending)]);
        break;

    case 'getPendingRecheckStacks':
        // Get list of stacks that need recheck
        $pendingRecheckFile = "/boot/config/plugins/compose.manager/pending-recheck.json";
        $pending = [];
        if (is_file($pendingRecheckFile)) {
            $pending = json_decode(file_get_contents($pendingRecheckFile), true) ?: [];
        }
        echo json_encode(['result' => 'success', 'pending' => $pending]);
        break;

    case 'clearStackRecheck':
        // Clear recheck flag for one or more stacks
        $stacks = isset($_POST['stacks']) ? $_POST['stacks'] : "";
        if (is_string($stacks)) {
            $stacks = json_decode($stacks, true);
        }
        if (!is_array($stacks) || empty($stacks)) {
            echo json_encode(['result' => 'error', 'message' => 'No stacks specified.']);
            break;
        }

        $pendingRecheckFile = "/boot/config/plugins/compose.manager/pending-recheck.json";
        $pending = [];
        if (is_file($pendingRecheckFile)) {
            $pending = json_decode(file_get_contents($pendingRecheckFile), true) ?: [];
        }

        // Remove stacks from pending list
        foreach ($stacks as $stackName) {
            unset($pending[$stackName]);
        }

        file_put_contents($pendingRecheckFile, json_encode($pending, JSON_PRETTY_PRINT));
        echo json_encode(['result' => 'success', 'remaining' => array_keys($pending)]);
        break;

    case 'createBackup':
        require_once("/usr/local/emhttp/plugins/compose.manager/php/backup_functions.php");
        exec("logger -t 'compose.manager' " . escapeshellarg("[backup] Manual backup starting..."));
        $result = createBackup();
        if ($result['result'] === 'success') {
            exec("logger -t 'compose.manager' " . escapeshellarg("[backup] Manual backup completed: " . $result['archive'] . " (" . $result['size'] . ", " . $result['stacks'] . " stacks)"));
        } else {
            exec("logger -t 'compose.manager' " . escapeshellarg("[backup] Manual backup FAILED: " . ($result['message'] ?? 'Unknown error')));
        }
        echo json_encode($result);
        break;

    case 'listBackups':
        require_once("/usr/local/emhttp/plugins/compose.manager/php/backup_functions.php");
        $directory = isset($_POST['directory']) && $_POST['directory'] !== '' ? trim($_POST['directory']) : null;
        $archives = listBackupArchives($directory);
        echo json_encode(['result' => 'success', 'archives' => $archives]);
        break;

    case 'uploadBackup':
        require_once("/usr/local/emhttp/plugins/compose.manager/php/backup_functions.php");
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errMsg = 'No file uploaded.';
            if (isset($_FILES['file'])) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit.',
                    UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit.',
                    UPLOAD_ERR_PARTIAL => 'File only partially uploaded.',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server missing temp directory.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk.',
                ];
                $errMsg = $uploadErrors[$_FILES['file']['error']] ?? 'Upload error code ' . $_FILES['file']['error'];
            }
            echo json_encode(['result' => 'error', 'message' => $errMsg]);
            break;
        }
        $filename = basename($_FILES['file']['name']);
        if (!preg_match('/\.(tar\.gz|tgz)$/i', $filename)) {
            echo json_encode(['result' => 'error', 'message' => 'Invalid file type. Only .tar.gz archives are accepted.']);
            break;
        }
        $dest = getBackupDestination();
        if (!is_dir($dest)) {
            if (!@mkdir($dest, 0755, true)) {
                echo json_encode(['result' => 'error', 'message' => 'Backup destination does not exist and could not be created: ' . $dest]);
                break;
            }
        }
        if (!is_writable($dest)) {
            echo json_encode(['result' => 'error', 'message' => 'Backup destination is not writable: ' . $dest]);
            break;
        }
        $targetPath = $dest . '/' . $filename;
        if (file_exists($targetPath)) {
            echo json_encode(['result' => 'error', 'message' => 'Archive "' . $filename . '" already exists in backup destination.']);
            break;
        }
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
            echo json_encode(['result' => 'error', 'message' => 'Failed to save uploaded file.']);
            break;
        }
        exec("logger -t 'compose.manager' " . escapeshellarg("[backup] Uploaded: $filename"));
        echo json_encode(['result' => 'success', 'message' => 'Archive uploaded successfully.', 'archive' => $filename]);
        break;

    case 'readManifest':
        require_once("/usr/local/emhttp/plugins/compose.manager/php/backup_functions.php");
        $archive = isset($_POST['archive']) ? trim($_POST['archive']) : '';
        $directory = isset($_POST['directory']) && $_POST['directory'] !== '' ? trim($_POST['directory']) : null;
        if (empty($archive)) {
            echo json_encode(['result' => 'error', 'message' => 'No archive specified.']);
            break;
        }
        $archivePath = resolveArchivePath($archive, $directory);
        $result = readArchiveStacks($archivePath);
        echo json_encode($result);
        break;

    case 'restoreBackup':
        require_once("/usr/local/emhttp/plugins/compose.manager/php/backup_functions.php");
        $archive = isset($_POST['archive']) ? trim($_POST['archive']) : '';
        $stacks = isset($_POST['stacks']) ? $_POST['stacks'] : '';
        if (is_string($stacks)) {
            $stacks = json_decode($stacks, true);
        }
        if (empty($archive)) {
            echo json_encode(['result' => 'error', 'message' => 'No archive specified.']);
            break;
        }
        if (!is_array($stacks) || empty($stacks)) {
            echo json_encode(['result' => 'error', 'message' => 'No stacks selected for restore.']);
            break;
        }
        exec("logger -t 'compose.manager' " . escapeshellarg("[restore] Restore started from " . basename($archive) . " (" . count($stacks) . " stacks)"));
        $archivePath = resolveArchivePath($archive);
        $result = restoreStacks($archivePath, $stacks);
        if ($result['result'] === 'error') {
            exec("logger -t 'compose.manager' " . escapeshellarg("[restore] Restore FAILED: " . ($result['message'] ?? 'Unknown error')));
        } else {
            $restoredList = implode(', ', $result['restored'] ?? []);
            exec("logger -t 'compose.manager' " . escapeshellarg("[restore] Restore completed: " . count($result['restored']) . " stacks restored (" . $restoredList . ")"));
            if (!empty($result['errors'])) {
                exec("logger -t 'compose.manager' " . escapeshellarg("[restore] Restore errors: " . implode(', ', $result['errors'])));
            }
        }
        echo json_encode($result);
        break;

    case 'deleteBackup':
        require_once("/usr/local/emhttp/plugins/compose.manager/php/backup_functions.php");
        $archive = isset($_POST['archive']) ? trim($_POST['archive']) : '';
        if (empty($archive)) {
            echo json_encode(['result' => 'error', 'message' => 'No archive specified.']);
            break;
        }
        $archivePath = resolveArchivePath($archive);
        if (!file_exists($archivePath)) {
            echo json_encode(['result' => 'error', 'message' => 'Archive not found.']);
            break;
        }
        @unlink($archivePath);
        exec("logger -t 'compose.manager' " . escapeshellarg("[backup] Deleted: " . basename($archive)));
        echo json_encode(['result' => 'success', 'message' => 'Backup deleted.']);
        break;

    case 'updateBackupCron':
        require_once("/usr/local/emhttp/plugins/compose.manager/php/backup_functions.php");
        updateBackupCron();
        echo json_encode(['result' => 'success', 'message' => 'Backup schedule updated.']);
        break;

    case 'saveBackupSettings':
        // Save backup settings to config file AND update cron
        $settings = $_POST['settings'] ?? '';
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }
        if (!is_array($settings)) {
            echo json_encode(['result' => 'error', 'message' => 'Invalid settings data.']);
            break;
        }

        // Write settings to config file
        $cfgFile = '/boot/config/plugins/compose.manager/compose.manager.cfg';
        $existingCfg = is_file($cfgFile) ? parse_ini_file($cfgFile) : [];
        $updatedCfg = array_merge($existingCfg, $settings);

        $lines = [];
        foreach ($updatedCfg as $key => $value) {
            $lines[] = "$key=\"$value\"";
        }
        file_put_contents($cfgFile, implode("\n", $lines) . "\n");

        // Update cron job and log the action
        require_once("/usr/local/emhttp/plugins/compose.manager/php/backup_functions.php");
        updateBackupCron();

        // Log the scheduler status
        $enabled = ($settings['BACKUP_SCHEDULE_ENABLED'] ?? 'false') === 'true';
        if ($enabled) {
            $freq = $settings['BACKUP_SCHEDULE_FREQUENCY'] ?? 'daily';
            $time = $settings['BACKUP_SCHEDULE_TIME'] ?? '03:00';
            $day = '';
            if ($freq === 'weekly') {
                $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                $dayNum = intval($settings['BACKUP_SCHEDULE_DAY'] ?? '1');
                $day = ' on ' . ($days[$dayNum] ?? 'Monday');
            }
            // Convert to 12-hour AM/PM format
            $timeParts = explode(':', $time);
            $hour = intval($timeParts[0]);
            $minute = $timeParts[1];
            $ampm = $hour >= 12 ? 'PM' : 'AM';
            $hour12 = $hour % 12;
            if ($hour12 === 0) $hour12 = 12;
            $time12 = "{$hour12}:{$minute} {$ampm}";
            exec("logger -t 'compose.manager' " . escapeshellarg("[backup] Scheduler ENABLED: {$freq}{$day} at {$time12}"));
        } else {
            exec("logger -t 'compose.manager' " . escapeshellarg("[backup] Scheduler DISABLED"));
        }

        echo json_encode(['result' => 'success', 'message' => 'Backup settings saved.']);
        break;
}
