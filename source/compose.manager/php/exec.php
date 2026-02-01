<?php

require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");

function getElement($element) {
    $return = str_replace(".","-",$element);
    $return = str_replace(" ","",$return);
    return $return;
}

switch ($_POST['action']) {
    case 'addStack':
        #Create indirect
        $indirect = isset($_POST['stackPath']) ? urldecode(($_POST['stackPath'])) : "";
        if ( !empty($indirect) ) {
            if ( !is_dir($indirect) ) {
                exec("mkdir -p ".escapeshellarg($indirect));
                if( !is_dir($indirect)  ) {
                    echo json_encode( [ 'result' => 'error', 'message' => 'Failed to create stack directory.' ] );
                    break;
                }
            }
        }

        #Pull stack files

        #Create stack folder
        $stackName = isset($_POST['stackName']) ? urldecode(($_POST['stackName'])) : "";
        $folderName = str_replace('"',"",$stackName);
        $folderName = str_replace("'","",$folderName);
        $folderName = str_replace("&","",$folderName);
        $folderName = str_replace("(","",$folderName);
        $folderName = str_replace(")","",$folderName);
        $folderName = preg_replace("/ {2,}/", " ", $folderName);
        $folderName = preg_replace("/\s/", "_", $folderName);
        $folder = "$compose_root/$folderName";
        while ( true ) {
          if ( is_dir($folder) ) {
            $folder .= mt_rand();
          } else {
            break;
          }
        }
        exec("mkdir -p ".escapeshellarg($folder));
        if( !is_dir($folder)  ) {
            echo json_encode( [ 'result' => 'error', 'message' => 'Failed to create stack directory.' ] );
            break;
        }

        #Create stack files
        if ( !empty($indirect) ) {
            file_put_contents("$folder/indirect",$indirect);
            if ( !is_file("$indirect/docker-compose.yml") ) {
                file_put_contents("$indirect/docker-compose.yml","services:\n");
            }
        } else {
            file_put_contents("$folder/docker-compose.yml","services:\n");
        }

        file_put_contents("$folder/name",$stackName);

        echo json_encode( [ 'result' => 'success', 'message' => '' ] );
        break;
    case 'deleteStack':
        $stackName = isset($_POST['stackName']) ? urldecode(($_POST['stackName'])) : "";
        if ( ! $stackName ) {
            echo json_encode( [ 'result' => 'error', 'message' => 'Stack not specified.' ] );
          break;
        }
        $folderName = "$compose_root/$stackName";
        $filesRemain = is_file("$folderName/indirect") ? file_get_contents("$folderName/indirect") : "";
        exec("rm -rf ".escapeshellarg($folderName));
        if ( !empty($filesRemain) ){
            echo json_encode( [ 'result' => 'warning', 'message' => $filesRemain ] );
        } else {
            echo json_encode( [ 'result' => 'success', 'message' => '' ] );
        }
        break;
    case 'changeName':
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        $newName = isset($_POST['newName']) ? urldecode(($_POST['newName'])) : "";
        file_put_contents("$compose_root/$script/name",trim($newName));
        echo json_encode( [ 'result' => 'success', 'message' => '' ] );
        break;
    case 'changeDesc':
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        $newDesc = isset($_POST['newDesc']) ? urldecode(($_POST['newDesc'])) : "";
        file_put_contents("$compose_root/$script/description",trim($newDesc));
        echo json_encode( [ 'result' => 'success', 'message' => '' ] );
        break;
    case 'getDescription':
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        if ( ! $script ) {
            echo json_encode( [ 'result' => 'error', 'message' => 'Stack not specified.' ] );
            break;
        }
        $fileName = "$compose_root/$script/description";
        $fileContents = is_file($fileName) ? file_get_contents($fileName) : "";
        $fileContents = str_replace("\r", "", $fileContents);
        echo json_encode( [ 'result' => 'success', 'content' => $fileContents ] );
        break;
    case 'getYml':
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        $basePath = getPath("$compose_root/$script");
        $fileName = "docker-compose.yml";

        $scriptContents = file_get_contents("$basePath/$fileName");
        $scriptContents = str_replace("\r","",$scriptContents);
        if ( ! $scriptContents ) {
            $scriptContents = "services:\n";
        }
        echo json_encode( [ 'result' => 'success', 'fileName' => "$basePath/$fileName", 'content' => $scriptContents ] );
        break;
    case 'getEnv':
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        $basePath = getPath("$compose_root/$script");
        $fileName = "$basePath/.env";
        if ( is_file("$basePath/envpath") ) {
            $fileName = file_get_contents("$basePath/envpath");
            $fileName = str_replace("\r","",$fileName);
        }

        $scriptContents = is_file("$fileName") ? file_get_contents("$fileName") : "";
        $scriptContents = str_replace("\r","",$scriptContents);
        if ( ! $scriptContents ) {
            $scriptContents = "\n";
        }
        echo json_encode( [ 'result' => 'success', 'fileName' => "$fileName", 'content' => $scriptContents ] );
        break;
    case 'getOverride':
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        $basePath = "$compose_root/$script";
        $fileName = "docker-compose.override.yml";

        $scriptContents = is_file("$basePath/$fileName") ? file_get_contents("$basePath/$fileName") : "";
        $scriptContents = str_replace("\r","",$scriptContents);
        if ( ! $scriptContents ) {
            $scriptContents = "";
        }
        echo json_encode( [ 'result' => 'success', 'fileName' => "$basePath/$fileName", 'content' => $scriptContents ] );
        break;
    case 'saveYml':
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        $scriptContents = isset($_POST['scriptContents']) ? $_POST['scriptContents'] : "";
        $basePath = getPath("$compose_root/$script");
        $fileName = "docker-compose.yml";
    
        file_put_contents("$basePath/$fileName",$scriptContents);
        echo "$basePath/$fileName saved";
        break;
    case 'saveEnv':
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        $scriptContents = isset($_POST['scriptContents']) ? $_POST['scriptContents'] : "";
        $basePath = getPath("$compose_root/$script");
        $fileName = "$basePath/.env";
        if ( is_file("$basePath/envpath") ) {
            $fileName = file_get_contents("$basePath/envpath");
            $fileName = str_replace("\r","",$fileName);
        }

        file_put_contents("$fileName",$scriptContents);
        echo "$fileName saved";
        break;
    case 'saveOverride':
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        $scriptContents = isset($_POST['scriptContents']) ? $_POST['scriptContents'] : "";
        $basePath = "$compose_root/$script";
        $fileName = "docker-compose.override.yml";

        file_put_contents("$basePath/$fileName",$scriptContents);
        echo "$basePath/$fileName saved";
        break;
    case 'updateAutostart':
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        if ( ! $script ) {
            echo json_encode( [ 'result' => 'error', 'message' => 'Stack not specified.' ] );
            break;
        }
        $autostart = isset($_POST['autostart']) ? urldecode(($_POST['autostart'])) : "false";
        $fileName = "$compose_root/$script/autostart";
        if ( is_file($fileName) ) {
            exec("rm ".escapeshellarg($fileName));
        }
        file_put_contents($fileName,$autostart);
        echo json_encode( [ 'result' => 'success', 'message' => '' ] );
        break;
    case 'patchUI':
        exec("$plugin_root/scripts/patch_ui.sh");
        break;
    case 'unPatchUI':
        exec("$plugin_root/scripts/patch_ui.sh -r");
        break;
    case 'setEnvPath':
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        if ( ! $script ) {
            echo json_encode( [ 'result' => 'error', 'message' => 'Stack not specified.' ] );
            break;
        }
        $fileContent = isset($_POST['envPath']) ? urldecode(($_POST['envPath'])) : "";
        $fileName = "$compose_root/$script/envpath";
        if ( is_file($fileName) ) {
            exec("rm ".escapeshellarg($fileName));
        }
        if ( isset($fileContent) && !empty($fileContent) ) {
            file_put_contents($fileName,$fileContent);
        }
        echo json_encode( [ 'result' => 'success', 'message' => '' ] );
        break;
    case 'getEnvPath':
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        if ( ! $script ) {
            echo json_encode( [ 'result' => 'error', 'message' => 'Stack not specified.' ] );
            break;
        }
        $fileName = "$compose_root/$script/envpath";
        $fileContents = is_file("$fileName") ? file_get_contents("$fileName") : "";
        $fileContents = str_replace("\r","",$fileContents);
        if ( ! $fileContents ) {
            $fileContents = "";
        }
        echo json_encode( [ 'result' => 'success', 'fileName' => "$fileName", 'content' => $fileContents ] );
        break;
    case 'getStackSettings':
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        if ( ! $script ) {
            echo json_encode( [ 'result' => 'error', 'message' => 'Stack not specified.' ] );
            break;
        }
        // Get env path
        $envPathFile = "$compose_root/$script/envpath";
        $envPath = is_file($envPathFile) ? trim(file_get_contents($envPathFile)) : "";
        
        // Get icon URL
        $iconUrlFile = "$compose_root/$script/icon_url";
        $iconUrl = is_file($iconUrlFile) ? trim(file_get_contents($iconUrlFile)) : "";
        
        echo json_encode([
            'result' => 'success',
            'envPath' => $envPath,
            'iconUrl' => $iconUrl
        ]);
        break;
    case 'setStackSettings':
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        if ( ! $script ) {
            echo json_encode( [ 'result' => 'error', 'message' => 'Stack not specified.' ] );
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
        
        echo json_encode(['result' => 'success', 'message' => 'Settings saved']);
        break;
    case 'saveProfiles':
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        $scriptContents = isset($_POST['scriptContents']) ? $_POST['scriptContents'] : "";
        $basePath = "$compose_root/$script";
        $fileName = "$basePath/profiles";

        if( $scriptContents == "[]" ) {
            if ( is_file($fileName) ) {
                exec("rm ".escapeshellarg($fileName));
                echo json_encode( [ 'result' => 'success', 'message' => "$fileName deleted" ] );
            }

            echo json_encode( [ 'result' => 'success', 'message' => '' ] );
            break;
        }

        file_put_contents("$fileName",$scriptContents);
        echo json_encode( [ 'result' => 'success', 'message' => "$fileName saved" ] );
        break;
    case 'getStackContainers':
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        if ( ! $script ) {
            echo json_encode( [ 'result' => 'error', 'message' => 'Stack not specified.' ] );
            break;
        }
        
        // Get the project name (sanitized)
        $projectName = $script;
        if ( is_file("$compose_root/$script/name") ) {
            $projectName = trim(file_get_contents("$compose_root/$script/name"));
        }
        $projectName = sanitizeStr($projectName);
        
        // Get containers for this compose project using docker compose ps
        $basePath = getPath("$compose_root/$script");
        $composeFile = "$basePath/docker-compose.yml";
        $overrideFile = "$compose_root/$script/docker-compose.override.yml";
        
        $files = "-f " . escapeshellarg($composeFile);
        if ( is_file($overrideFile) ) {
            $files .= " -f " . escapeshellarg($overrideFile);
        }
        
        $envFile = "";
        if ( is_file("$compose_root/$script/envpath") ) {
            $envPath = trim(file_get_contents("$compose_root/$script/envpath"));
            if ( is_file($envPath) ) {
                $envFile = "--env-file " . escapeshellarg($envPath);
            }
        }
        
        // Get container details in JSON format
        $cmd = "docker compose $files $envFile -p " . escapeshellarg($projectName) . " ps --format json 2>/dev/null";
        $output = shell_exec($cmd);
        
        $containers = [];
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
                                    
                                    // Get ports
                                    $ports = [];
                                    $portBindings = $inspect['HostConfig']['PortBindings'] ?? [];
                                    foreach ($portBindings as $containerPort => $bindings) {
                                        if ($bindings) {
                                            foreach ($bindings as $binding) {
                                                $hostPort = $binding['HostPort'] ?? '';
                                                $hostIp = $binding['HostIp'] ?? '0.0.0.0';
                                                if ($hostPort) {
                                                    $ports[] = "$hostIp:$hostPort->$containerPort";
                                                }
                                            }
                                        }
                                    }
                                    $container['Ports'] = $ports;
                                    
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
                                    
                                    // Get network info
                                    $networks = [];
                                    $networkSettings = $inspect['NetworkSettings']['Networks'] ?? [];
                                    foreach ($networkSettings as $netName => $netConfig) {
                                        $networks[] = [
                                            'name' => $netName,
                                            'ip' => $netConfig['IPAddress'] ?? ''
                                        ];
                                    }
                                    $container['Networks'] = $networks;
                                    
                                    // Get labels for WebUI
                                    $labels = $inspect['Config']['Labels'] ?? [];
                                    $container['WebUI'] = $labels[$docker_label_webui] ?? '';
                                    $container['Icon'] = $labels[$docker_label_icon] ?? '';
                                    $container['Shell'] = $labels[$docker_label_shell] ?? '/bin/bash';
                                }
                            }
                        }
                        $containers[] = $container;
                    }
                }
            }
        }
        
        echo json_encode([ 'result' => 'success', 'containers' => $containers, 'projectName' => $projectName ]);
        break;
    case 'containerAction':
        $containerName = isset($_POST['container']) ? urldecode(($_POST['container'])) : "";
        $containerAction = isset($_POST['containerAction']) ? urldecode(($_POST['containerAction'])) : "";
        
        if ( ! $containerName || ! $containerAction ) {
            echo json_encode( [ 'result' => 'error', 'message' => 'Container or action not specified.' ] );
            break;
        }
        
        $allowedActions = ['start', 'stop', 'restart', 'pause', 'unpause'];
        if ( !in_array($containerAction, $allowedActions) ) {
            echo json_encode( [ 'result' => 'error', 'message' => 'Invalid action.' ] );
            break;
        }
        
        $cmd = "docker " . escapeshellarg($containerAction) . " " . escapeshellarg($containerName) . " 2>&1";
        $output = shell_exec($cmd);
        
        echo json_encode([ 'result' => 'success', 'message' => trim($output) ]);
        break;
    case 'checkStackUpdates':
        // Check for updates for all containers in a compose stack
        $script = isset($_POST['script']) ? urldecode(($_POST['script'])) : "";
        if ( ! $script ) {
            echo json_encode( [ 'result' => 'error', 'message' => 'Stack not specified.' ] );
            break;
        }
        
        // Include Docker manager classes for update checking
        require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");
        
        // Get the project name (sanitized)
        $projectName = $script;
        if ( is_file("$compose_root/$script/name") ) {
            $projectName = trim(file_get_contents("$compose_root/$script/name"));
        }
        $projectName = sanitizeStr($projectName);
        
        // Get containers for this compose project
        $basePath = getPath("$compose_root/$script");
        $composeFile = "$basePath/docker-compose.yml";
        $overrideFile = "$compose_root/$script/docker-compose.override.yml";
        
        $files = "-f " . escapeshellarg($composeFile);
        if ( is_file($overrideFile) ) {
            $files .= " -f " . escapeshellarg($overrideFile);
        }
        
        $envFile = "";
        if ( is_file("$compose_root/$script/envpath") ) {
            $envPath = trim(file_get_contents("$compose_root/$script/envpath"));
            if ( is_file($envPath) ) {
                $envFile = "--env-file " . escapeshellarg($envPath);
            }
        }
        
        // Get container images
        $cmd = "docker compose $files $envFile -p " . escapeshellarg($projectName) . " ps --format json 2>/dev/null";
        $output = shell_exec($cmd);
        
        $updateResults = [];
        $DockerUpdate = new DockerUpdate();
        
        // Load the update status file to get SHA values
        $dockerManPaths = [
            'update-status' => "/var/lib/docker/unraid-update-status.json"
        ];
        
        if ($output) {
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                if (!empty($line)) {
                    $container = json_decode($line, true);
                    if ($container) {
                        $containerName = $container['Name'] ?? '';
                        $image = $container['Image'] ?? '';
                        
                        if ($containerName && $image) {
                            // Ensure image has a tag
                            if (strpos($image, ':') === false) {
                                $image .= ':latest';
                            }
                            
                            // Clear cached local SHA to force re-inspection of the actual image
                            // This is needed because Unraid's reloadUpdateStatus uses cached values
                            // which can be stale after docker compose pull
                            $updateStatusData = DockerUtil::loadJSON($dockerManPaths['update-status']);
                            $imageLookupKey = $image;
                            if (!isset($updateStatusData[$image]) && strpos($image, '/') === false) {
                                $imageLookupKey = 'library/' . $image;
                            }
                            if (isset($updateStatusData[$imageLookupKey])) {
                                // Clear the local SHA to force fresh inspection
                                $updateStatusData[$imageLookupKey]['local'] = null;
                                DockerUtil::saveJSON($dockerManPaths['update-status'], $updateStatusData);
                            }
                            
                            // Check update status using Unraid's DockerUpdate class
                            $DockerUpdate->reloadUpdateStatus($image);
                            $updateStatus = $DockerUpdate->getUpdateStatus($image);
                            
                            // Get SHA values from the status file
                            $updateStatusData = DockerUtil::loadJSON($dockerManPaths['update-status']);
                            $localSha = '';
                            $remoteSha = '';
                            
                            // Try to find the image in the status file
                            // Docker Hub official images are stored with library/ prefix
                            $imageLookupKey = $image;
                            if (!isset($updateStatusData[$image]) && strpos($image, '/') === false) {
                                // Try with library/ prefix for Docker Hub official images
                                $imageLookupKey = 'library/' . $image;
                            }
                            
                            if (isset($updateStatusData[$imageLookupKey])) {
                                $localSha = $updateStatusData[$imageLookupKey]['local'] ?? '';
                                $remoteSha = $updateStatusData[$imageLookupKey]['remote'] ?? '';
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
        
        echo json_encode([ 'result' => 'success', 'updates' => $updateResults, 'projectName' => $projectName ]);
        
        // Save the update status for this stack
        $composeUpdateStatusFile = "/boot/config/plugins/compose.manager/update-status.json";
        $savedStatus = [];
        if (is_file($composeUpdateStatusFile)) {
            $savedStatus = json_decode(file_get_contents($composeUpdateStatusFile), true) ?: [];
        }
        $savedStatus[$script] = [
            'projectName' => $projectName,
            'hasUpdate' => count(array_filter($updateResults, function($r) { return $r['hasUpdate']; })) > 0,
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
            if ( is_file("$compose_root/$stackName/name") ) {
                $projectName = trim(file_get_contents("$compose_root/$stackName/name"));
            }
            $projectName = sanitizeStr($projectName);
            
            // Get containers
            $files = "-f " . escapeshellarg($composeFile);
            $overrideFile = "$compose_root/$stackName/docker-compose.override.yml";
            if ( is_file($overrideFile) ) {
                $files .= " -f " . escapeshellarg($overrideFile);
            }
            
            $cmd = "docker compose $files -p " . escapeshellarg($projectName) . " ps --format json 2>/dev/null";
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
                                if (strpos($image, ':') === false) {
                                    $image .= ':latest';
                                }
                                
                                // Clear cached local SHA to force re-inspection of the actual image
                                // This is needed because Unraid's reloadUpdateStatus uses cached values
                                // which can be stale after docker compose pull
                                $updateStatusData = DockerUtil::loadJSON($dockerManPaths['update-status']);
                                $imageLookupKey = $image;
                                if (!isset($updateStatusData[$image]) && strpos($image, '/') === false) {
                                    $imageLookupKey = 'library/' . $image;
                                }
                                if (isset($updateStatusData[$imageLookupKey])) {
                                    // Clear the local SHA to force fresh inspection
                                    $updateStatusData[$imageLookupKey]['local'] = null;
                                    DockerUtil::saveJSON($dockerManPaths['update-status'], $updateStatusData);
                                }
                                
                                $DockerUpdate->reloadUpdateStatus($image);
                                $updateStatus = $DockerUpdate->getUpdateStatus($image);
                                
                                // Get SHA values from the status file
                                $updateStatusData = DockerUtil::loadJSON($dockerManPaths['update-status']);
                                $localSha = '';
                                $remoteSha = '';
                                
                                // Try to find the image in the status file
                                // Docker Hub official images are stored with library/ prefix
                                $imageLookupKey = $image;
                                if (!isset($updateStatusData[$image]) && strpos($image, '/') === false) {
                                    // Try with library/ prefix for Docker Hub official images
                                    $imageLookupKey = 'library/' . $image;
                                }
                                
                                if (isset($updateStatusData[$imageLookupKey])) {
                                    $localSha = $updateStatusData[$imageLookupKey]['local'] ?? '';
                                    $remoteSha = $updateStatusData[$imageLookupKey]['remote'] ?? '';
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
        
        echo json_encode([ 'result' => 'success', 'stacks' => $allUpdates ]);
        break;
    case 'getSavedUpdateStatus':
        // Load saved update status from file
        $composeUpdateStatusFile = "/boot/config/plugins/compose.manager/update-status.json";
        if (is_file($composeUpdateStatusFile)) {
            $savedStatus = json_decode(file_get_contents($composeUpdateStatusFile), true);
            if ($savedStatus) {
                echo json_encode([ 'result' => 'success', 'stacks' => $savedStatus ]);
            } else {
                echo json_encode([ 'result' => 'success', 'stacks' => [] ]);
            }
        } else {
            echo json_encode([ 'result' => 'success', 'stacks' => [] ]);
        }
        break;
}

?>