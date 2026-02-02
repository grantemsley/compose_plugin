<?PHP
/**
 * Async stack list loader for Compose Manager
 * This file is called via AJAX to load the stack list without blocking page load
 */

require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");

// Helper function for combo buttons (same as in main file)
function createComboButton($text, $id, $onClick, $onClickParams, $items) {
  $o = "";
  $o .= "<div class='combo-btn-group'>";
  $o .= "<input type='button' value='$text' class='combo-btn-group-left' id='$id-left-btn' onclick='$onClick($onClickParams);'>";
  $o .= "<section class='combo-btn-subgroup dropdown'>";
  $o .= "<button type='button' class='dropdown-toggle combo-btn-group-right' data-toggle='dropdown'><i class='fa fa-caret-down'></i></button>";
  $o .= "<div class='dropdown-content'>";
  foreach ( $items as $item ) {
    $o .= "<a href='#' onclick='$onClick($onClickParams, &quot;$item&quot;);'>$item</a>";
  }
  $o .= "</div>";
  $o .= "</section>";
  $o .= "</div>";
  return $o;
}

// Get stack state
$stackstate = shell_exec($plugin_root."/scripts/compose.sh -c list");
$stackstate = json_decode($stackstate, TRUE);

// Get all compose containers with status/uptime info
$containersOutput = shell_exec($plugin_root."/scripts/compose.sh -c ps");
$containersByProject = [];
if ($containersOutput) {
  $lines = explode("\n", trim($containersOutput));
  foreach ($lines as $line) {
    if (!empty($line)) {
      $container = json_decode($line, true);
      if ($container && isset($container['Labels'])) {
        // Extract project name from labels
        if (preg_match('/com\.docker\.compose\.project=([^,]+)/', $container['Labels'], $matches)) {
          $projectName = $matches[1];
          if (!isset($containersByProject[$projectName])) {
            $containersByProject[$projectName] = [];
          }
          $containersByProject[$projectName][] = $container;
        }
      }
    }
  }
}

$composeProjects = @array_diff(@scandir($compose_root),array(".",".."));
if ( ! is_array($composeProjects) ) {
  $composeProjects = array();
}

$o = "";
$stackCount = 0;

foreach ($composeProjects as $project) {
  if ( ( ! is_file("$compose_root/$project/docker-compose.yml") ) &&
       ( ! is_file("$compose_root/$project/indirect") ) ) {
    continue;
  }

  $stackCount++;
  
  $projectName = $project;
  if ( is_file("$compose_root/$project/name") ) {
    $projectName = trim(file_get_contents("$compose_root/$project/name"));
  }
  $id = str_replace(".","-",$project);
  $id = str_replace(" ","",$id);

  // Get the compose file path
  $basePath = is_file("$compose_root/$project/indirect") 
    ? trim(file_get_contents("$compose_root/$project/indirect")) 
    : "$compose_root/$project";
  $composeFile = "$basePath/docker-compose.yml";
  $overrideFile = "$compose_root/$project/docker-compose.override.yml";
  
  // Use docker compose config --services to get accurate service count
  // This properly parses YAML, handles overrides, extends, etc.
  $definedServices = 0;
  if (is_file($composeFile)) {
    $files = "-f " . escapeshellarg($composeFile);
    if (is_file($overrideFile)) {
      $files .= " -f " . escapeshellarg($overrideFile);
    }
    
    // Get env file if specified
    $envFile = "";
    if (is_file("$compose_root/$project/envpath")) {
      $envPath = trim(file_get_contents("$compose_root/$project/envpath"));
      if (is_file($envPath)) {
        $envFile = "--env-file " . escapeshellarg($envPath);
      }
    }
    
    // Use docker compose config --services to list all service names
    $cmd = "docker compose $files $envFile config --services 2>/dev/null";
    $output = shell_exec($cmd);
    if ($output) {
      $services = array_filter(explode("\n", trim($output)));
      $definedServices = count($services);
    }
  }

  // Get running container info from $containersByProject
  $sanitizedProjectName = sanitizeStr($projectName);
  $projectContainers = $containersByProject[$sanitizedProjectName] ?? [];
  $runningCount = 0;
  $stoppedCount = 0;
  $pausedCount = 0;
  $restartingCount = 0;
  
  foreach ($projectContainers as $ct) {
    $ctState = $ct['State'] ?? '';
    if ($ctState === 'running') {
      $runningCount++;
    } elseif ($ctState === 'exited') {
      $stoppedCount++;
    } elseif ($ctState === 'paused') {
      $pausedCount++;
    } elseif ($ctState === 'restarting') {
      $restartingCount++;
    }
  }
  
  // Container counts
  $actualContainerCount = count($projectContainers);
  $containerCount = $definedServices > 0 ? $definedServices : $actualContainerCount;
  
  // Determine states
  $isrunning = $runningCount > 0;
  $isexited = $stoppedCount > 0;
  $ispaused = $pausedCount > 0;
  $isrestarting = $restartingCount > 0;
  $isup = $actualContainerCount > 0;

  if ( is_file("$compose_root/$project/description") ) {
    $description = @file_get_contents("$compose_root/$project/description");
    $description = str_replace("\r","",$description);
    $description = str_replace("\n","<br>",$description);
  } else {
    $description = isset($variables['description']) ? $variables['description'] : "No description<br>($compose_root/$project)";
  }

  $autostart = '';
  if ( is_file("$compose_root/$project/autostart") ) {
    $autostarttext = @file_get_contents("$compose_root/$project/autostart");
    if ( strpos($autostarttext, 'true') !== false ) {
      $autostart = 'checked';
    }
  }

  // Check for custom project icon (URL-based only via icon_url file)
  $projectIcon = '';
  if (is_file("$compose_root/$project/icon_url")) {
    $iconUrl = trim(@file_get_contents("$compose_root/$project/icon_url"));
    if (filter_var($iconUrl, FILTER_VALIDATE_URL) && (strpos($iconUrl, 'http://') === 0 || strpos($iconUrl, 'https://') === 0)) {
      $projectIcon = $iconUrl;
    }
  }

  $profiles = array();
  if ( is_file("$compose_root/$project/profiles") ) {
    $profilestext = @file_get_contents("$compose_root/$project/profiles");
    $profiles = json_decode($profilestext, false);
  }
  $profilesJson = json_encode($profiles ? $profiles : []);

  // Determine status text and class for badge
  $statusText = "Stopped";
  $statusClass = "status-stopped";
  if ( $isup ) {
    if ( $isexited && !$isrunning ) {
      $statusText = "Exited";
      $statusClass = "status-exited";
    } elseif ( $isrunning && !$isexited && !$ispaused && !$isrestarting ) {
      $statusText = "Running";
      $statusClass = "status-running";
    } elseif ( $ispaused && !$isexited && !$isrunning && !$isrestarting ) {
      $statusText = "Paused";
      $statusClass = "status-paused";
    } elseif ( $ispaused && !$isexited ) {
      $statusText = "Partial";
      $statusClass = "status-partial";
    } elseif ( $isrestarting ) {
      $statusText = "Restarting";
      $statusClass = "status-restarting";
    } else {
      $statusText = "Mixed";
      $statusClass = "status-mixed";
    }
  }

  // Escape for HTML output
  $projectNameHtml = htmlspecialchars($projectName, ENT_QUOTES, 'UTF-8');
  $projectHtml = htmlspecialchars($project, ENT_QUOTES, 'UTF-8');
  $descriptionHtml = $description; // Already contains <br> tags from earlier processing
  $pathHtml = htmlspecialchars("$compose_root/$project", ENT_QUOTES, 'UTF-8');
  $projectIconUrl = htmlspecialchars($projectIcon, ENT_QUOTES, 'UTF-8');

  // Status like Docker tab (started/stopped with icon)
  $shape = $isrunning ? 'play' : 'square';
  $status = $isrunning ? ($runningCount == $containerCount ? 'started' : 'partial') : 'stopped';
  $color = $status == 'started' ? 'green-text' : ($status == 'partial' ? 'orange-text' : 'grey-text');
  $outerClass = $isrunning ? ($runningCount == $containerCount ? 'started' : 'paused') : 'stopped';
  
  $statusLabel = $status;
  if ($status == 'partial') {
    $statusLabel = "partial ($runningCount/$containerCount)";
  }

  // Calculate stack uptime from containers
  $stackUptime = '';
  $sanitizedProjectName = sanitizeStr($projectName);
  if (isset($containersByProject[$sanitizedProjectName])) {
    $projectContainers = $containersByProject[$sanitizedProjectName];
    $longestUptime = '';
    foreach ($projectContainers as $ct) {
      if (isset($ct['Status']) && strpos($ct['Status'], 'Up') === 0) {
        // Extract uptime from Status like "Up 6 months (healthy)"
        if (preg_match('/Up ([^(]+)/', $ct['Status'], $uptimeMatch)) {
          $longestUptime = trim($uptimeMatch[1]);
          break; // Use first running container's uptime
        }
      }
    }
    if ($longestUptime) {
      $stackUptime = "Uptime: " . $longestUptime;
    }
  }
  if (!$stackUptime && $isrunning) {
    $stackUptime = "Uptime: running";
  } elseif (!$stackUptime) {
    $stackUptime = "stopped";
  }

  // Main row - Docker tab structure with expand arrow on left
  $o .= "<tr class='compose-sortable' id='stack-row-$id' data-project='$projectHtml' data-projectname='$projectNameHtml' data-path='$pathHtml' data-isup='$isup' data-profiles='$profilesJson'>";
  
  // Name column: expand arrow, then icon with context menu, then name
  $o .= "<td class='ct-name' style='width:220px;padding:8px'>";
  // Expand arrow on the left (separate from the outer/inner structure)
  $o .= "<span style='display:inline-block;width:20px;text-align:center;vertical-align:middle;'>";
  $o .= "<i class='fa fa-chevron-right expand-icon' id='expand-icon-$id' onclick='toggleStackDetails(\"$id\");event.stopPropagation();' style='cursor:pointer;'></i>";
  $o .= "</span>";
  // Icon and name using Docker's outer/inner structure
  $o .= "<span class='outer $outerClass'>";
  $o .= "<span id='stack-$id' class='hand' data-stackid='$id' data-project='$projectHtml' data-projectname='$projectNameHtml' data-isup='$isup' data-running='" . ($isrunning ? '1' : '0') . "'>";
  // Use actual image - either custom icon URL or default question.png like Docker tab
  $imgSrc = $projectIconUrl ?: '/plugins/dynamix.docker.manager/images/question.png';
  $o .= "<img src='$imgSrc' class='img' onerror=\"this.src='/plugins/dynamix.docker.manager/images/question.png';\">";
  $o .= "</span>";
  $o .= "<span class='inner'><span class='appname'>$projectNameHtml</span><br>";
  $o .= "<i class='fa fa-$shape $status $color'></i><span class='state'>$statusLabel</span>";
  // Advanced: show project folder
  $o .= "<div class='advanced' style='margin-top:4px;font-size:0.85em;color:#888;'>";
  $o .= "Project: $projectHtml";
  $o .= "</div>";
  $o .= "</span></span>";
  $o .= "</td>";
  
  // Update column (like Docker tab) - default to "not checked" until update check runs
  $o .= "<td class='compose-updatecolumn'>";
  if ($isrunning) {
    $o .= "<span class='grey-text' style='white-space:nowrap;cursor:default;' title='Click Check for Updates to check'><i class='fa fa-question-circle fa-fw'></i> not checked</span>";
  } else {
    $o .= "<span class='grey-text' style='white-space:nowrap;'><i class='fa fa-docker fa-fw'></i> stopped</span>";
  }
  $o .= "</td>";
  
  // Containers column (shows running/total)
  $containersDisplay = $isrunning ? "$runningCount / $containerCount" : "0 / $containerCount";
  $containersClass = ($runningCount == $containerCount && $runningCount > 0) ? 'green-text' : ($runningCount > 0 ? 'orange-text' : 'grey-text');
  $o .= "<td><span class='$containersClass'>$containersDisplay</span></td>";
  
  // Uptime column (both basic and advanced views)
  $uptimeDisplay = $stackUptime;
  $uptimeClass = $isrunning ? 'green-text' : 'grey-text';
  $o .= "<td><span class='$uptimeClass'>$uptimeDisplay</span></td>";
  
  // Description column (advanced only)
  $o .= "<td class='advanced' style='word-break:break-all;'><span class='docker_readmore'>$descriptionHtml</span></td>";
  
  // Version/Image info column (advanced only) - shows compose file info
  $composeVersion = '';
  if (isset($containersByProject[$sanitizedProjectName][0]['Labels'])) {
    if (preg_match('/com\.docker\.compose\.version=([^,]+)/', $containersByProject[$sanitizedProjectName][0]['Labels'], $vMatch)) {
      $composeVersion = 'Compose v' . $vMatch[1];
    }
  }
  $o .= "<td class='advanced' style='color:#606060;font-size:12px;'>$composeVersion</td>";
  
  // Path column (advanced only)
  $o .= "<td class='advanced' style='color:#606060;font-size:12px;'>$pathHtml</td>";
  
  // Auto Start toggle
  $o .= "<td class='nine'>";
  $o .= "<input type='checkbox' class='auto_start autostart' data-scriptName='$projectHtml' id='autostart-$id' $autostart>";
  $o .= "</td>";
  
  $o .= "</tr>";
  
  // Expandable details row (hidden by default)
  $o .= "<tr class='stack-details-row' id='details-row-$id' style='display:none;'>";
  $o .= "<td colspan='10' class='stack-details-cell' style='padding:0 0 0 60px;background:rgba(0,0,0,0.05);'>";
  $o .= "<div class='stack-details-container' id='details-container-$id' style='padding:8px 16px;'>";
  $o .= "<i class='fa fa-spinner fa-spin'></i> Loading containers...";
  $o .= "</div>";
  $o .= "</td>";
  $o .= "</tr>";
}

// If no stacks found, show a message
if ($stackCount === 0) {
  $o = "<tr><td colspan='8' style='text-align:center;padding:20px;color:#888;'>No Docker Compose stacks found. Click 'Add New Stack' to create one.</td></tr>";
}

// Output the HTML
echo $o;
