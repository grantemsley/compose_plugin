<?PHP

require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");

function createComboButton($text, $id, $onClick, $onClickParams, $items) {
  $o = "";

  $o .= "<div class='combo-btn-group'>";
  $o .= "<input type='button' value='$text' class='combo-btn-group-left' id='$id-left-btn' onclick='$onClick($onClickParams);'>";
  $o .= "<section class='combo-btn-subgroup dropdown'>";
  $o .= "<button type='button' class='dropdown-toggle combo-btn-group-right' data-toggle='dropdown'><i class='fa fa-caret-down'></i></button>";
  $o .= "<div class='dropdown-content'>";
  foreach ( $items as $item )
  {
    $o .= "<a href='#' onclick='$onClick($onClickParams, &quot;$item&quot;);'>$item</a>";
  }
  $o .= "</div>";
  $o .= "</section>";
  $o .= "</div>";

  return $o;
}

$vars = parse_ini_file("/var/local/emhttp/var.ini");

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
foreach ($composeProjects as $project) {
  if ( ( ! is_file("$compose_root/$project/docker-compose.yml") ) &&
       ( ! is_file("$compose_root/$project/indirect") ) ) {
    continue;
  }

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
  
  // Parse compose file to count services (basic YAML parsing)
  $definedServices = 0;
  if (is_file($composeFile)) {
    $composeContent = @file_get_contents($composeFile);
    if ($composeContent) {
      // Count services by looking for top-level keys under 'services:'
      // Simple approach: count lines that match pattern for service names
      if (preg_match('/^services:\s*$/m', $composeContent)) {
        // Find lines after 'services:' that are indented by exactly 2 spaces and have a name followed by colon
        if (preg_match_all('/^  ([a-zA-Z0-9_-]+):\s*$/m', $composeContent, $matches)) {
          $definedServices = count($matches[1]);
        }
      }
    }
  }
  
  // Also check override file
  $overrideFile = "$compose_root/$project/docker-compose.override.yml";
  if (is_file($overrideFile)) {
    $overrideContent = @file_get_contents($overrideFile);
    if ($overrideContent && preg_match('/^services:\s*$/m', $overrideContent)) {
      if (preg_match_all('/^  ([a-zA-Z0-9_-]+):\s*$/m', $overrideContent, $matches)) {
        // Add any new services from override (avoid double counting)
        $definedServices = max($definedServices, count($matches[1]));
      }
    }
  }
  
  // If parsing failed, fallback to 0
  if ($definedServices < 1) $definedServices = 0;

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
  $o .= "<tr class='sortable' id='stack-row-$id' data-project='$projectHtml' data-projectname='$projectNameHtml' data-path='$pathHtml' data-isup='$isup' data-profiles='$profilesJson'>";
  
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
  
  // Update column (like Docker tab)
  $o .= "<td class='updatecolumn'>";
  if ($isrunning) {
    $o .= "<a class='exec' style='cursor:pointer;' onclick=\"showUpdateWarning('$projectHtml', '$id');\" title='Check for updates'>";
    $o .= "<span style='white-space:nowrap;'><i class='fa fa-cloud-download fa-fw'></i> pull updates</span>";
    $o .= "</a>";
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
?>

<script src="/plugins/compose.manager/javascript/ace/ace.js" type= "text/javascript"></script>
<script src="/plugins/compose.manager/javascript/js-yaml/js-yaml.min.js" type= "text/javascript"></script>
<script>
var compose_root=<?php echo json_encode($compose_root); ?>;
var caURL = "/plugins/compose.manager/php/exec.php";
var compURL = "/plugins/compose.manager/php/compose_util.php";
var aceTheme=<?php echo (in_array($theme,['black','gray']) ? json_encode('ace/theme/tomorrow_night') : json_encode('ace/theme/tomorrow')); ?>;
const icon_label = <?php echo json_encode($docker_label_icon); ?>;
const webui_label = <?php echo json_encode($docker_label_webui); ?>;
const shell_label = <?php echo json_encode($docker_label_shell); ?>;

$('head').append( $('<link rel="stylesheet" type="text/css" />').attr('href', '<?autov("/plugins/compose.manager/styles/comboButton.css");?>') );

if (typeof swal2 === "undefined") {
    $('head').append( $('<link rel="stylesheet" type="text/css" />').attr('href', '<?autov("/plugins/compose.manager/styles/sweetalert2.css");?>') );
		$.getScript( '/plugins/compose.manager/javascript/sweetalert/sweetalert2.min.js');
}

function basename( path ) {
  return path.replace( /\\/g, '/' ).replace( /.*\//, '' );
}

function dirname( path ) {
  return path.replace( /\\/g, '/' ).replace( /\/[^\/]*$/, '' );
}

// HTML escape function to prevent XSS
function escapeHtml(text) {
  if (text === null || text === undefined) return '';
  var div = document.createElement('div');
  div.textContent = String(text);
  return div.innerHTML;
}

// Escape for HTML attributes (more strict)
function escapeAttr(text) {
  if (text === null || text === undefined) return '';
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

// Update status cache per stack
var stackUpdateStatus = {};

// Check for updates for all stacks
function checkAllUpdates() {
  $('#checkUpdatesBtn').prop('disabled', true).val('Checking...');
  
  // Show checking indicator on all update columns
  $('.updatecolumn').each(function() {
    var $link = $(this).find('a');
    if ($link.length) {
      $link.html('<i class="fa fa-refresh fa-spin"></i> checking...');
    }
  });
  
  $.post(caURL, {action: 'checkAllStacksUpdates'}, function(data) {
    if (data) {
      try {
        var response = JSON.parse(data);
        if (response.result === 'success' && response.stacks) {
          stackUpdateStatus = response.stacks;
          
          // Update the UI for each stack
          for (var stackName in response.stacks) {
            var stackInfo = response.stacks[stackName];
            updateStackUpdateUI(stackName, stackInfo);
          }
        }
      } catch(e) {
        console.error('Failed to parse update check response:', e);
      }
    }
    $('#checkUpdatesBtn').prop('disabled', false).val('Check for Updates');
  }).fail(function() {
    $('#checkUpdatesBtn').prop('disabled', false).val('Check for Updates');
    // Reset update columns
    $('.updatecolumn a').each(function() {
      $(this).html('<i class="fa fa-cloud-download"></i> pull updates');
    });
  });
}

// Update UI for a single stack's update status
function updateStackUpdateUI(stackName, stackInfo) {
  // Find the stack row by project name
  var $stackRow = $('[data-project="' + stackName + '"]').filter('tr.sortable');
  if ($stackRow.length === 0) return;
  
  var stackId = $stackRow.attr('id').replace('stack-row-', '');
  var $updateCell = $stackRow.find('.updatecolumn');
  
  // Count updates
  var updateCount = 0;
  var totalContainers = stackInfo.containers ? stackInfo.containers.length : 0;
  
  if (stackInfo.containers) {
    stackInfo.containers.forEach(function(ct) {
      if (ct.hasUpdate) updateCount++;
    });
  }
  
  // Update the stack row's update column (match Docker tab style)
  if (updateCount > 0) {
    // Updates available - orange "update ready" style with clickable link
    $updateCell.html('<a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + escapeAttr(stackName) + '\', \'' + escapeAttr(stackId) + '\');"><span class="orange-text" style="white-space:nowrap;"><i class="fa fa-flash fa-fw"></i> ' + updateCount + ' update' + (updateCount > 1 ? 's' : '') + '</span></a>');
  } else if (totalContainers > 0) {
    // No updates - green "up-to-date" style (like Docker tab)
    // Basic view: just shows up-to-date
    // Advanced view: shows force update link
    var html = '<span class="green-text" style="white-space:nowrap;"><i class="fa fa-check fa-fw"></i> up-to-date</span>';
    html += '<div class="advanced"><a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + escapeAttr(stackName) + '\', \'' + escapeAttr(stackId) + '\');"><span style="white-space:nowrap;"><i class="fa fa-cloud-download fa-fw"></i> force update</span></a></div>';
    $updateCell.html(html);
  } else {
    // No containers found - show pull updates as clickable (for stacks that aren't running)
    $updateCell.html('<a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + escapeAttr(stackName) + '\', \'' + escapeAttr(stackId) + '\');"><i class="fa fa-cloud-download fa-fw"></i> pull updates</a>');
  }
  
  // Rebuild context menus to reflect update status
  $('[id^="stack-"][data-project="' + stackName + '"]').each(function() {
    addComposeStackContext(this.id);
  });
  
  // Also update the cached container data with update status and SHA
  if (stackContainersCache[stackId] && stackInfo.containers) {
    stackContainersCache[stackId].forEach(function(cached) {
      stackInfo.containers.forEach(function(updated) {
        if (cached.Name === updated.container) {
          cached.hasUpdate = updated.hasUpdate;
          cached.updateStatus = updated.status;
          cached.localSha = updated.localSha || '';
          cached.remoteSha = updated.remoteSha || '';
        }
      });
    });
  }
  
  // If details are expanded, refresh them
  if (expandedStacks[stackId]) {
    loadStackContainerDetails(stackId, stackName);
  }
}

// Check updates for a single stack
function checkStackUpdates(stackName) {
  var $stackRow = $('[data-project="' + stackName + '"]').filter('tr.sortable');
  if ($stackRow.length === 0) return;
  
  var $updateCell = $stackRow.find('.updatecolumn');
  $updateCell.html('<span style="color:#267CA8"><i class="fa fa-refresh fa-spin"></i> checking...</span>');
  
  $.post(caURL, {action: 'checkStackUpdates', script: stackName}, function(data) {
    if (data) {
      try {
        var response = JSON.parse(data);
        if (response.result === 'success') {
          var stackInfo = {
            projectName: response.projectName,
            hasUpdate: false,
            containers: response.updates
          };
          response.updates.forEach(function(u) {
            if (u.hasUpdate) stackInfo.hasUpdate = true;
          });
          stackUpdateStatus[stackName] = stackInfo;
          updateStackUpdateUI(stackName, stackInfo);
        }
      } catch(e) {
        console.error('Failed to parse update check response:', e);
      }
    }
  });
}

// Validate URL scheme for WebUI links
function isValidWebUIUrl(url) {
  if (!url) return false;
  var lowerUrl = url.toLowerCase().trim();
  return lowerUrl.startsWith('http://') || lowerUrl.startsWith('https://');
}

$(function() {
  var editor = ace.edit("itemEditor");
  editor.setTheme(aceTheme);
  editor.setShowPrintMargin(false);
})

// Apply advanced/basic view based on cookie
function applyListView() {
  var advanced = $.cookie('compose_listview_mode') === 'advanced';
  if (advanced) {
    $('.advanced').show();
    $('.basic').hide();
  } else {
    $('.advanced').hide();
    $('.basic').show();
  }
  // Apply readmore to descriptions
  $('.docker_readmore').readmore({maxHeight:32,moreLink:"<a href='#' style='text-align:center'><i class='fa fa-chevron-down'></i></a>",lessLink:"<a href='#' style='text-align:center'><i class='fa fa-chevron-up'></i></a>"});
}

$(function() {
	$(".tipsterallowed").show();
	$('.ca_nameEdit').tooltipster({
		trigger: 'custom',
		triggerOpen: {click:true,touchstart:true,mouseenter:true},
		triggerClose:{click:true,scroll:false,mouseleave:true},
		delay: 1000,
		contentAsHTML: true,
		animation: 'grow',
		interactive: true,
		viewportAware: true,
		functionBefore: function(instance,helper) {
			var origin = $(helper.origin);
			var myID = origin.attr('id');
			var name = $("#"+myID).html();
      var disabled = $("#"+myID).attr('data-isup') == "1" ? "disabled" : "";
      var notdisabled = $("#"+myID).attr('data-isup') == "1" ? "" : "disabled";
			var stackName = $("#"+myID).attr("data-scriptname");
      instance.content(stackName + "<br> \
                                    <center> \
                                    <input type='button' onclick='editName(&quot;"+myID+"&quot;);' value='Edit Name' "+disabled+"> \
                                    <input type='button' onclick='editDesc(&quot;"+myID+"&quot;);' value='Edit Description' > \
                                    <input type='button' onclick='editStack(&quot;"+myID+"&quot;);' value='Edit Stack'> \
                                    <input type='button' onclick='deleteStack(&quot;"+myID+"&quot;);' value='Delete Stack' "+disabled+"> \
                                    <input type='button' onclick='ComposeLogs(&quot;"+myID+"&quot;);' value='Logs' "+notdisabled+"> \
                                    </center>");
		}
	});
  $('.auto_start').switchButton({labels_placement:'right', on_label:"On", off_label:"Off"});
  $('.auto_start').change(function(){
      var script = $(this).attr("data-scriptname");
      var auto = $(this).prop('checked');
      $.post(caURL,{action:'updateAutostart',script:script,autostart:auto});
    });
  
  // Initialize context menus for stack icons
  $('[id^="stack-"][data-stackid]').each(function() {
    addComposeStackContext(this.id);
  });
  
  // Add Advanced View toggle (like Docker tab)
  $(".tabs").append('<span class="status"><span><input type="checkbox" class="advancedview"></span></span>');
  $('.advancedview').switchButton({labels_placement:'left', on_label:'Advanced View', off_label:'Basic View', checked:$.cookie('compose_listview_mode')==='advanced'});
  $('.advancedview').change(function(){
    $('.advanced').toggle('slow');
    $('.basic').toggle('slow');
    $.cookie('compose_listview_mode', $('.advancedview').is(':checked') ? 'advanced' : 'basic', {expires:3650});
  });
  
  // Apply initial view state
  applyListView();
});

function addStack() {
  var form = document.createElement("div");
  // form.classList.add("swal-content");
  form.innerHTML = `<input type="text" id="stack_name" class="swal-content__input" placeholder="stack_name">
                    <br>
                    <details>
                      <summary style="text-align: left">Advanced</summary>
                      <br>
                      <div class="swal-text">Stack Directory</div>
                      <input type="text" id="stack_path" class="swal-content__input" pattern="\/mnt\/.*\/.*" oninput="this.reportValidity()" title="A path under /mnt/user/ or /mnt/cache/ or /mnt/pool/" style="margin-top: 20px" placeholder="default">
                      <div style="display:none;">
                        <div class="swal-text">Pull From Github</div>
                        <input type="url" id="git_url" class="swal-content__input" style="margin-top: 20px" placeholder="https://github.com/example/repo.git">
                      </div>
                    </details>`;
  swal2({
    title: "Add New Compose Stack",
    text: "Enter in the name for the stack",
    content: form,
    buttons: true,
  }).then((inputValue) => {
    if (inputValue) {
      var new_stack_name = document.getElementById("stack_name").value;
      var new_stack_dir = document.getElementById("stack_path").value;
      var git_url = document.getElementById("git_url").value;
      if (!new_stack_name) {
        swal2({
          title: "Failed to create stack.",
          text: "Stack name unspecified.",
          icon: "error",
        })
      }
      else {
        $.post(
          caURL,
          {action:'addStack',stackName:new_stack_name,stackPath:new_stack_dir},
          function(data) {
            var title = "Failed to create stack.";
            var message = "";
            var icon = "error";
            if (data) {
              var response = JSON.parse(data);
              if (response.result == "success") {
                title = "Success";
              }
              message = response.message;
              icon = response.result;
            }
            swal2({
              title: title,
              text: message,
              icon: icon,
            }).then(() => {
              location.reload();
            });
          }
        );        
      }
    }
  });
}

function deleteStack(myID) {
  var stackName = $("#"+myID).attr("data-scriptname");
  var project = $("#"+myID).attr("data-namename");
  var element = document.createElement("div")
  element.innerHTML = "Are you sure you want to delete <font color='red'><b>"+project+"</b></font> (<font color='green'>"+compose_root+"/"+stackName+"</font>)?"; 
  swal2({
    content: element,
    title: "Delete Stack?",
    icon: "warning",
    buttons: true,
    dangerMode: true,
  }).then((willDelete) => {
    if (willDelete) {
      $.post(caURL,{action:'deleteStack',stackName:stackName},function(data) {
        if (data) {
          var response = JSON.parse(data);
          if (response.result == "warning") {
            title = "Success";
            swal2({
              title: "Files remain on disk.",
              text: response.message,
              icon: "warning",
            }).then(() => {
              location.reload();
            });
          } else {
            location.reload();
          }
        } else {
            location.reload();
        }
      });
    }
  });
}

function stripTags(string) {
	return string.replace(/(<([^>]+)>)/ig,"");
}

function editName(myID) {
	// console.log(myID);
  var currentName = $("#"+myID).attr("data-namename");
  $("#"+myID).attr("data-originalName",currentName);
  $("#"+myID).html("<input type='text' id='newName"+myID+"' value='"+currentName+"'><br><font color='red' size='4'><i class='fa fa-times' aria-hidden='true' style='cursor:pointer' onclick='cancelName(&quot;"+myID+"&quot;);'></i>&nbsp;&nbsp;<font color='green' size='4'><i style='cursor:pointer' onclick='applyName(&quot;"+myID+"&quot;);' class='fa fa-check' aria-hidden='true'></i></font>");
  $("#"+myID).tooltipster("close");
  $("#"+myID).tooltipster("disable");
}

function editDesc(myID) {
  var origID = myID;
  $("#"+myID).tooltipster("close");
  myID = myID.replace("name","desc");
  var currentDesc = $("#"+myID).html();
  $("#"+myID).attr("data-originaldescription",currentDesc);
  $("#"+myID).html("<textarea id='newDesc"+myID+"' cols='40' rows='5'>"+currentDesc+"</textarea><br><font color='red' size='4'><i class='fa fa-times' aria-hidden='true' style='cursor:pointer' onclick='cancelDesc(&quot;"+myID+"&quot;);'></i>&nbsp;&nbsp;<font color='green' size='4'><i style='cursor:pointer' onclick='applyDesc(&quot;"+myID+"&quot;); ' class='fa fa-check' aria-hidden='true'></i></font>");
  $("#"+origID).tooltipster("enable");
}

function applyName(myID) {
  var newName = $("#newName"+myID).val();
  var project = $("#"+myID).attr("data-scriptname");
  $("#"+myID).html(newName);
  $("#"+myID).tooltipster("enable");
  $("#"+myID).tooltipster("close");
  $.post(caURL,{action:'changeName',script:project,newName:newName},function(data) {
		window.location.reload();
	});
}

function cancelName(myID) {
  var oldName = $("#"+myID).attr("data-originalName");
  $("#"+myID).html(oldName);
  $("#"+myID).tooltipster("enable");
  $("#"+myID).tooltipster("close");
	window.location.reload();
}

function cancelDesc(myID) {
  var oldName = $("#"+myID).attr("data-originaldescription");
  $("#"+myID).html(oldName);
  $("#"+myID).tooltipster("enable");
  $("#"+myID).tooltipster("close");
}

function applyDesc(myID) {
  var newDesc = $("#newDesc"+myID).val();
  newDesc = newDesc.replace(/\n/g, "<br>");
  var project = $("#"+myID).attr("data-scriptname");
  $("#"+myID).html(newDesc);
  $.post(caURL,{action:'changeDesc',script:project,newDesc:newDesc});
}

function editStack(myID) {
  var buttonsList = {};

  buttonsList["compose_file"] = { text: "Compose File" };
  buttonsList["env_file"] = { text: "ENV File" };
  buttonsList["override_file"] = { text: "UI Labels" };
  buttonsList["stack_settings"] = { text: "Stack Settings" };

  buttonsList["Cancel"] = { text: "Cancel", value: null, };
  swal2({
    title: "Select Stack File to Edit",
    className: 'edit-stack-form',
    buttons: buttonsList,
  }).then((result) => {
    if (result) {
      switch(result) {
        case 'compose_file':
          editComposeFile(myID);
          break;
        case 'env_file':
          editEnv(myID);
          break;
        case 'override_file':
          generateOverride(myID);
          break;
        case 'stack_settings':
          editStackSettings(myID);
          break;
        default:
          return;
      }
    }
  });
}

function build_override_input_table( id, value, label, placeholder, disable=false) {
  var disabled = disable ? `disabled` : ``;
  html = `<div style="display:table; width:100%;">`;
  html += `<label for="${id}" style="width:75px; display:table-cell;">${label}</label>`;
  html += `<input type="text" id="${id}" class="swal-content__input" placeholder="${placeholder}" value="${value}" style="width:100%; display:table-cell;" ${disabled}>`;
  html += `</div>`;
  html += `<br>`;

  return html;
}

function override_find_labels( primary, secondary, label ) {
  var value = primary.labels[label] || "";
  if( !value && "labels" in secondary ) {
    value = secondary.labels[label] || "";
  }

  return value;
}

function generateOverride(myID, myProject=null) {
  var project = myProject;
  if( myID ) {
    $("#"+myID).tooltipster("close");
    project = $("#"+myID).attr("data-scriptname");
  }
    
  $.post(caURL,{action:'getOverride',script:project},function(rawOverride) {
    if (rawOverride) {
      var rawOverride = JSON.parse(rawOverride);
      $.post(caURL,{action:'getYml',script:project},function(rawComposefile) {
        if (rawComposefile) {
          var rawComposefile = JSON.parse(rawComposefile);

          if( (rawOverride.result == 'success') && (rawComposefile.result == 'success') ) {
            var override_doc = jsyaml.load(rawOverride.content);
            if( !override_doc ) {
              override_doc = { services: {} };
            }
            var main_doc = jsyaml.load(rawComposefile.content);

            for( var service_key in main_doc.services ) {
              if( !(service_key in override_doc.services) ) {
                override_doc.services[service_key] = { 
                  labels: {  
                    <?php echo json_encode($docker_label_managed); ?>: <?php echo json_encode($docker_label_managed_name); ?>,
                  } 
                };
              }
            }

            var html = ``;
            for( var service_key in override_doc.services ) {
              if( service_key in main_doc.services ) {
                var name = main_doc.services[service_key].container_name || service_key;
                html += `<div class="swal-text" style="font-weight: bold; padding-left: 0px; margin-top: 0px;">Service: ${name}</div>`;
                html += `<br>`;

                var icon_value = override_find_labels(override_doc.services[service_key], main_doc.services[service_key], icon_label);
                html += build_override_input_table(`${service_key}_icon`, icon_value, "Icon", "icon");
                
                var webui_value = override_find_labels(override_doc.services[service_key], main_doc.services[service_key], webui_label);
                html += build_override_input_table(`${service_key}_webui`, webui_value, "Web UI", "web ui");

                var shell_value = override_find_labels(override_doc.services[service_key], main_doc.services[service_key], shell_label);
                html += build_override_input_table(`${service_key}_shell`, shell_value, "Shell", "shell");
              }
            }
            var deleted_entries = ``;
            for( var service_key in override_doc.services ) {
              if( !(service_key in main_doc.services) ) {
                var name = override_doc.services[service_key].container_name || service_key;
                deleted_entries += `<div class="swal-text" style="font-weight: bold; padding-left: 0px; margin-top: 0px;">Service: ${name}</div>`;
                deleted_entries += `<br>`;

                var icon_value = override_find_labels(override_doc.services[service_key], override_doc.services[service_key], icon_label);
                deleted_entries += build_override_input_table(`${service_key}_icon_d`, icon_value, "Icon", "", true);
                
                var webui_value = override_find_labels(override_doc.services[service_key], override_doc.services[service_key], webui_label);
                deleted_entries += build_override_input_table(`${service_key}_webui_d`, webui_value, "Web UI", "", true);

                var shell_value = override_find_labels(override_doc.services[service_key], override_doc.services[service_key], shell_label);
                deleted_entries += build_override_input_table(`${service_key}_shell_d`, shell_value, "Shell", "", true);
              }
            }
            if( deleted_entries ) {
              html += `<details>
                       <summary style="text-align: left; font-weight: bold;">Entries to be Deleted</summary>
                       <br>`;
              html += deleted_entries;
              html += `</details>`;
            }

            var form = document.createElement("div");
            form.style["text-align"] = "left";
            form.innerHTML = html;
            swal2({
              title: "Edit Stack UI Labels",
              content: form,
              buttons: true,
            }).then((result) => {
              if(result) {
                for( var service_key in override_doc.services ) {
                  if( service_key in main_doc.services ) {
                    var new_icon = document.getElementById(`${service_key}_icon`).value;
                    var new_webui = document.getElementById(`${service_key}_webui`).value;
                    var new_shell = document.getElementById(`${service_key}_shell`).value;

                    override_doc.services[service_key].labels[icon_label] = new_icon;
                    override_doc.services[service_key].labels[webui_label] = new_webui;
                    override_doc.services[service_key].labels[shell_label] = new_shell;
                  }
                  else {
                    delete override_doc.services[service_key];
                  }
                }

                rawOverride = jsyaml.dump(override_doc, {'forceQuotes': true});
                // console.log(rawOverride);
                $.post(caURL,{action:"saveOverride",script:project,scriptContents:rawOverride},function(data) {
                  if (!data) {
                    swal2({
                      title: "Failed to update labels.",
                      icon: "error",
                    })
                  }
                });
              }
            });
          }
        }
      });
    }
  });
}

function generateProfiles(myID, myProject=null) {
  var project = myProject;
  if( myID ) {
    $("#"+myID).tooltipster("close");
    project = $("#"+myID).attr("data-scriptname");
  }

  $.post(caURL,{action:'getYml',script:project},function(rawComposefile) {
    var project_profiles = new Set();
    if(rawComposefile) {
      var rawComposefile = JSON.parse(rawComposefile);

      if( (rawComposefile.result == 'success') ) {
        var main_doc = jsyaml.load(rawComposefile.content);

        for( var service_key in main_doc.services ) {
          var service = main_doc.services[service_key];
          if( service.hasOwnProperty("profiles") ) {
            // console.log(service.profiles);
            for( const profile of service.profiles ) {
              project_profiles.add(profile);
            }
          }
        }
        
        // console.log(project_profiles);
        var rawProfiles = JSON.stringify(Array.from(project_profiles));
        // console.log(rawProfiles);
        $.post(caURL,{action:"saveProfiles",script:project,scriptContents:rawProfiles},function(data) {
          if (!data) {
            swal2({
              title: "Failed to update profiles.",
              icon: "error",
            })
          }
        });
      }
    }
  });
}

function editComposeFile(myID) {
  var origID = myID;
  $("#"+myID).tooltipster("close");
  var project = $("#"+myID).attr("data-scriptname");
  $.post(caURL,{action:'getYml',script:project},function(data) {
    if (data) {
      var response = JSON.parse(data);
      var editor = ace.edit("itemEditor");
      editor.getSession().setValue(response.content);
      editor.getSession().setMode("ace/mode/yaml");
      editor.getSession().setOptions({ tabSize: 2, useSoftTabs: true });

      $('#editorFileName').data("stackname", project);
      $('#editorFileName').data("stackfilename", "docker-compose.yml")
      $('#editorFileName').html(response.fileName)
      $(".editing").show();
			window.scrollTo(0, 0);
    }
  });
}

function editEnv(myID) {
  var origID = myID;
  $("#"+myID).tooltipster("close");
  var project = $("#"+myID).attr("data-scriptname");
  $.post(caURL,{action:'getEnv',script:project},function(data) {
    if (data) {
      var response = JSON.parse(data);
      var editor = ace.edit("itemEditor");
      editor.getSession().setValue(response.content);
      editor.getSession().setMode("ace/mode/sh");

      $('#editorFileName').data("stackname", project);
      $('#editorFileName').data("stackfilename", ".env")
      $('#editorFileName').html(response.fileName)
      $(".editing").show();
			window.scrollTo(0, 0);
    }
  });
}

function cancelEdit() {
  $(".editing").hide();
}

function saveEdit() {
  var project = $("#editorFileName").data("stackname");
  var fileName = $("#editorFileName").data("stackfilename");
  var editor = ace.edit("itemEditor");
  var scriptContents = editor.getValue();
  var actionStr = null

  switch(fileName) {
    case 'docker-compose.yml':
      actionStr = 'saveYml'
      break;

    case '.env':
      actionStr = 'saveEnv'
      break;

    default:
      $(".editing").hide();
      return;
  }

  $.post(caURL,{action:actionStr,script:project,scriptContents:scriptContents},function(data) {
    if (data) {
      $(".editing").hide();
      if (actionStr == 'saveYml') {
        generateOverride(null,project);
        generateProfiles(null,project);
      }
    }
  });

}

function editStackSettings(myID) {
  var project = $("#"+myID).attr("data-scriptname");

  $.post(caURL,{action:'getEnvPath',script:project},function(rawEnvPath) {
    if (rawEnvPath) {
      var rawEnvPath = JSON.parse(rawEnvPath);
      if(rawEnvPath.result == 'success') {
        var form = document.createElement("div");
        // form.classList.add("swal-content");
        form.innerHTML =  `<div class="swal-text" style="font-weight: bold; padding-left: 0px; margin-top: 0px;">ENV File Path</div>`;
        form.innerHTML += `<br>`;
        form.innerHTML += `<input type='text' id='env_path' class='swal-content__input' pattern="(\/mnt\/.*\/.+)" oninput="this.reportValidity()" title="A path under /mnt/user/ or /mnt/cache/ or /mnt/pool/" placeholder=Default value='${rawEnvPath.content}'>`;
        swal2({
          title: "Stack Settings",
          // text: "Enter in the name for the stack",
          content: form,
          buttons: true,
        }).then((inputValue) => {
          if (inputValue) {
            var new_env_path = document.getElementById("env_path").value;
            $.post(caURL,{action:'setEnvPath',envPath:new_env_path,script:project},function(data) {
                var title = "Failed to set stack settings.";
                var message = "";
                var icon = "error";
                if (data) {
                  var response = JSON.parse(data);
                  if (response.result == "success") {
                    title = "Success";
                  }
                  message = response.message;
                  icon = response.result;
                }
                swal2({
                  title: title,
                  text: message,
                  icon: icon,
                }).then(() => {
                  location.reload();
                });
            });        
          }
        });
      }
    }
  });
}

// Unified update warning dialog - called from stack row and container table
function showUpdateWarning(project, stackId) {
  var path = compose_root + '/' + project;
  // Use the existing UpdateStack function which already has the warning dialog
  UpdateStack(path, "");
}

// Confirmed action handlers (no dialog, just execute)
function ComposeUpConfirmed(path, profile="") {
  var height = 800;
  var width = 1200;
  
  $.post(compURL,{action:'composeUp',path:path,profile:profile},function(data) {
    if (data) {
      openBox(data,"Stack "+basename(path)+" Up",height,width,true);
    }
  })
}

function ComposeUp(path, profile="") {
  showStackActionDialog('up', path, profile);
}

function ComposeDownConfirmed(path, profile="") {
  var height = 800;
  var width = 1200;

  $.post(compURL,{action:'composeDown',path:path,profile:profile},function(data) {
    if (data) {
      openBox(data,"Stack "+basename(path)+" Down",height,width,true);
    }
  })
}

function ComposeDown(path, profile="") {
  showStackActionDialog('down', path, profile);
}

function UpdateStackConfirmed(path, profile="") {
  var height = 800;
  var width = 1200;

  $.post(compURL,{action:'composeUpPullBuild',path:path,profile:profile},function(data) {
    if (data) {
      openBox(data,"Update Stack "+basename(path),height,width,true);
    }
  })
}

function UpdateStack(path, profile="") {
  showStackActionDialog('update', path, profile);
}

// Unified stack action dialog - handles up, down, and update actions
function showStackActionDialog(action, path, profile) {
  var stackName = basename(path);
  var project = stackName;
  
  // Find the stack row
  var $stackRow = $('[data-project="' + project + '"]').filter('tr.sortable');
  var stackId = '';
  if ($stackRow.length > 0) {
    stackId = $stackRow.attr('id').replace('stack-row-', '');
  }
  
  // Check if we have cached container data
  if (stackId && stackContainersCache[stackId] && stackContainersCache[stackId].length > 0) {
    renderStackActionDialog(action, stackName, path, profile, stackContainersCache[stackId]);
  } else {
    // Fetch container details first
    $.post(caURL, {action: 'getStackContainers', script: project}, function(data) {
      var containers = [];
      if (data) {
        try {
          var response = JSON.parse(data);
          if (response.result === 'success' && response.containers) {
            containers = response.containers;
            if (stackId) {
              stackContainersCache[stackId] = containers;
            }
          }
        } catch(e) {}
      }
      renderStackActionDialog(action, stackName, path, profile, containers);
    }).fail(function() {
      renderStackActionDialog(action, stackName, path, profile, []);
    });
  }
}

function renderStackActionDialog(action, stackName, path, profile, containers) {
  // Action-specific configuration
  var config = {
    'up': {
      title: 'Start Stack?',
      description: 'This will start all containers in <b>' + escapeHtml(stackName) + '</b>.',
      listTitle: 'CONTAINERS TO START',
      warning: 'Images will be pulled if not present locally.',
      warningIcon: 'info-circle',
      warningColor: '#08f',
      confirmText: 'Yes, start stack',
      showVersionArrow: false,
      confirmedFn: ComposeUpConfirmed
    },
    'down': {
      title: 'Stop Stack?',
      description: 'This will stop and remove all containers in <b>' + escapeHtml(stackName) + '</b>.',
      listTitle: 'CONTAINERS TO STOP',
      warning: 'Containers will be stopped and removed. Data in volumes will be preserved.',
      warningIcon: 'exclamation-triangle',
      warningColor: '#f80',
      confirmText: 'Yes, stop stack',
      showVersionArrow: false,
      confirmedFn: ComposeDownConfirmed
    },
    'update': {
      title: 'Update Stack?',
      description: 'This will pull the latest images and recreate containers in <b>' + escapeHtml(stackName) + '</b>.',
      listTitle: 'CONTAINERS TO UPDATE',
      warning: 'Running containers will be briefly interrupted.',
      warningIcon: 'exclamation-triangle',
      warningColor: '#f80',
      confirmText: 'Yes, update stack',
      showVersionArrow: true,
      confirmedFn: UpdateStackConfirmed
    }
  };
  
  var cfg = config[action];
  if (!cfg) return;
  
  // Build HTML content for the dialog
  var html = '<div style="text-align:left;max-width:450px;margin:0 auto;">';
  html += '<div style="margin-bottom:15px;">' + cfg.description + '</div>';
  
  // Container list with icons
  if (containers && containers.length > 0) {
    html += '<div style="background:rgba(0,0,0,0.2);border-radius:4px;padding:10px;margin:10px 0;">';
    html += '<div style="font-weight:bold;margin-bottom:8px;font-size:0.9em;color:#999;"><i class="fa fa-cubes"></i> ' + cfg.listTitle + '</div>';
    
    containers.forEach(function(container) {
      var containerName = container.Name || container.Service || 'Unknown';
      var shortName = containerName.replace(/^[^-]+-/, '');
      var image = container.Image || '';
      var imageParts = image.split(':');
      var imageName = imageParts[0].split('/').pop();
      var imageTag = imageParts[1] || 'latest';
      var state = container.State || 'unknown';
      var stateColor = state === 'running' ? '#3c3' : (state === 'paused' ? '#f80' : '#888');
      var stateIcon = state === 'running' ? 'play' : (state === 'paused' ? 'pause' : 'square');
      
      // Check if this container has an update available
      var hasUpdate = container.hasUpdate || false;
      var updateStatus = container.updateStatus || 'unknown';
      var localSha = container.localSha || '';
      var remoteSha = container.remoteSha || '';
      
      var iconSrc = (container.Icon && (container.Icon.indexOf('http://') === 0 || container.Icon.indexOf('https://') === 0 || container.Icon.indexOf('data:image/') === 0)) 
                    ? escapeAttr(container.Icon) 
                    : '/plugins/dynamix.docker.manager/images/question.png';
      
      // Grey out containers without updates when showing update dialog
      var rowOpacity = (cfg.showVersionArrow && !hasUpdate && updateStatus === 'up-to-date') ? '0.5' : '1';
      
      html += '<div style="display:flex;align-items:center;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.1);opacity:' + rowOpacity + ';">';
      html += '<img src="' + iconSrc + '" style="width:28px;height:28px;margin-right:10px;border-radius:4px;" onerror="this.src=\'/plugins/dynamix.docker.manager/images/question.png\'">';
      html += '<div style="flex:1;">';
      html += '<div style="font-weight:bold;">' + escapeHtml(shortName);
      // Show update badge if update is available (for update action)
      if (cfg.showVersionArrow && hasUpdate) {
        html += ' <span style="background:#f80;color:#fff;font-size:0.7em;padding:2px 6px;border-radius:3px;margin-left:6px;">UPDATE</span>';
      } else if (cfg.showVersionArrow && updateStatus === 'up-to-date') {
        html += ' <span style="color:#3c3;font-size:0.8em;margin-left:6px;"><i class="fa fa-check"></i></span>';
      }
      html += '</div>';
      html += '<div style="font-size:0.85em;color:#999;">';
      html += '<i class="fa fa-' + stateIcon + '" style="color:' + stateColor + ';margin-right:4px;"></i>';
      html += escapeHtml(imageName) + ':<span style="color:#f0a000;">' + escapeHtml(imageTag) + '</span>';
      
      // Show SHA info for update action
      if (cfg.showVersionArrow) {
        if (hasUpdate && localSha && remoteSha) {
          // Has update - show current SHA → new SHA
          html += '<br><span style="font-family:monospace;font-size:0.9em;">';
          html += '<span style="color:#f80;">' + escapeHtml(localSha) + '</span>';
          html += ' <i class="fa fa-arrow-right" style="margin:0 4px;color:#3c3;"></i> ';
          html += '<span style="color:#3c3;">' + escapeHtml(remoteSha) + '</span>';
          html += '</span>';
        } else if (localSha) {
          // No update - just show current SHA (greyed)
          html += '<br><span style="font-family:monospace;font-size:0.9em;color:#666;">' + escapeHtml(localSha) + '</span>';
        }
      }
      html += '</div></div></div>';
    });
    
    html += '</div>';
  }
  
  // Warning/info text
  html += '<div style="color:' + cfg.warningColor + ';margin-top:10px;font-size:0.9em;"><i class="fa fa-' + cfg.warningIcon + '"></i> ' + cfg.warning + '</div>';
  html += '</div>';
  
  // Use native swal (SweetAlert 1.x) with callback style
  swal({
    title: cfg.title,
    text: html,
    html: true,
    type: 'warning',
    showCancelButton: true,
    confirmButtonText: cfg.confirmText,
    cancelButtonText: 'Cancel'
  }, function(confirmed) {
    if (confirmed) {
      cfg.confirmedFn(path, profile);
    }
  });
}

function ComposeLogs(myID) {
  var height = 800;
  var width = 1200;
  var project = myID;
  var path = compose_root + "/" + project;
  console.log(path);
  $.post(compURL,{action:'composeLogs',path:path},function(data) {
    if (data) {
      openBox(data,"Stack "+basename(path)+" Logs",height,width,true);
    }
  })
}

// ============================================
// Stack Actions Menu Functions
// ============================================
var currentStackId = null;
var expandedStacks = {};
var stackContainersCache = {};

function openStackActionsMenu(event, stackId) {
  event.stopPropagation();
  currentStackId = stackId;
  
  var $row = $('#stack-row-' + stackId);
  var projectName = $row.data('projectname');
  var isUp = $row.data('isup') == "1";
  
  // Update modal title
  $('.stack-actions-modal-title').text(projectName);
  
  // Show/hide certain actions based on state
  // Delete is disabled when stack is running
  var $deleteBtn = $('.stack-action-item:contains("Delete Stack")');
  if (isUp) {
    $deleteBtn.addClass('disabled').prop('disabled', true);
  } else {
    $deleteBtn.removeClass('disabled').prop('disabled', false);
  }
  
  // Position and show modal
  var $modal = $('#stack-actions-modal');
  var $overlay = $('#stack-actions-overlay');
  
  // Get button position for modal placement
  var $btn = $('#kebab-' + stackId);
  var btnOffset = $btn.offset();
  var btnHeight = $btn.outerHeight();
  
  // Position modal near the button
  $modal.css({
    top: btnOffset.top + btnHeight + 5,
    right: $(window).width() - btnOffset.left - $btn.outerWidth()
  });
  
  $overlay.show();
  $modal.show();
}

function closeStackActionsMenu() {
  $('#stack-actions-modal').hide();
  $('#stack-actions-overlay').hide();
  currentStackId = null;
}

function executeStackAction(action) {
  if (!currentStackId) return;
  
  var $row = $('#stack-row-' + currentStackId);
  var project = $row.data('project');
  var projectName = $row.data('projectname');
  var path = $row.data('path');
  var profiles = $row.data('profiles') || [];
  var isUp = $row.data('isup') == "1";
  
  closeStackActionsMenu();
  
  // Handle profile selection if profiles exist and action supports it
  var profileSupportedActions = ['up', 'down', 'update'];
  if (profiles.length > 0 && profileSupportedActions.includes(action)) {
    showProfileSelector(action, path, profiles);
    return;
  }
  
  switch(action) {
    case 'up':
      ComposeUp(path);
      break;
    case 'down':
      ComposeDown(path);
      break;
    case 'update':
      UpdateStack(path);
      break;
    case 'logs':
      ComposeLogs(project);
      break;
    case 'editFiles':
      openEditorModalByProject(project, projectName);
      break;
    case 'editName':
      showEditNameDialog(currentStackId, project, projectName);
      break;
    case 'editDesc':
      showEditDescDialog(currentStackId, project);
      break;
    case 'uiLabels':
      generateOverride(null, project);
      break;
    case 'settings':
      editStackSettingsByProject(project);
      break;
    case 'delete':
      if (!isUp) {
        deleteStackByProject(project, projectName);
      }
      break;
  }
}

function showProfileSelector(action, path, profiles) {
  var buttonsList = {};
  buttonsList["default"] = { text: "All Services (Default)" };
  profiles.forEach(function(profile) {
    buttonsList[profile] = { text: profile };
  });
  buttonsList["Cancel"] = { text: "Cancel", value: null };
  
  var actionNames = {
    'up': 'Compose Up',
    'down': 'Compose Down',
    'update': 'Update Stack'
  };
  
  swal2({
    title: "Select Profile",
    text: "Choose which profile to use for " + actionNames[action],
    buttons: buttonsList,
  }).then((result) => {
    if (result && result !== 'Cancel') {
      var profile = result === 'default' ? '' : result;
      switch(action) {
        case 'up':
          ComposeUp(path, profile);
          break;
        case 'down':
          ComposeDown(path, profile);
          break;
        case 'update':
          UpdateStack(path, profile);
          break;
      }
    }
  });
}

function openEditorModalByProject(project, projectName) {
  // Show edit stack dialog - compatible with main branch
  // When editor modal feature is merged, this can be updated to use it
  var buttonsList = {};

  buttonsList["compose_file"] = { text: "Compose File" };
  buttonsList["env_file"] = { text: "ENV File" };
  buttonsList["override_file"] = { text: "Override File" };

  buttonsList["Cancel"] = { text: "Cancel", value: null };
  swal2({
    title: "Edit Stack Files",
    text: "Select which file to edit for " + projectName,
    className: 'edit-stack-form',
    buttons: buttonsList,
  }).then((result) => {
    if (result) {
      switch(result) {
        case 'compose_file':
          editComposeFileByProject(project);
          break;
        case 'env_file':
          editEnvByProject(project);
          break;
        case 'override_file':
          editOverrideByProject(project);
          break;
        default:
          return;
      }
    }
  });
}

function editComposeFileByProject(project) {
  $.post(caURL,{action:'getYml',script:project},function(data) {
    if (data) {
      var response = JSON.parse(data);
      var filename = response.fileName;
      $(".tipsterallowed").hide();
      $(".editing").show();
      $("#editorFileName").html(filename);
      $("#editorFileName").attr("data-stackname",project);
      $("#editorFileName").attr("data-stackfilename","docker-compose.yml");
      var editor = ace.edit("itemEditor");
      editor.getSession().setMode('ace/mode/yaml');
      editor.setValue(response.content, -1);
    }
  });
}

function editEnvByProject(project) {
  $.post(caURL,{action:'getEnv',script:project},function(data) {
    if (data) {
      var response = JSON.parse(data);
      var filename = response.fileName;
      $(".tipsterallowed").hide();
      $(".editing").show();
      $("#editorFileName").html(filename);
      $("#editorFileName").attr("data-stackname",project);
      $("#editorFileName").attr("data-stackfilename",".env");
      var editor = ace.edit("itemEditor");
      editor.getSession().setMode('ace/mode/sh');
      editor.setValue(response.content, -1);
    }
  });
}

function editOverrideByProject(project) {
  $.post(caURL,{action:'getOverride',script:project},function(data) {
    if (data) {
      var response = JSON.parse(data);
      var filename = response.fileName;
      $(".tipsterallowed").hide();
      $(".editing").show();
      $("#editorFileName").html(filename);
      $("#editorFileName").attr("data-stackname",project);
      $("#editorFileName").attr("data-stackfilename","docker-compose.override.yml");
      var editor = ace.edit("itemEditor");
      editor.getSession().setMode('ace/mode/yaml');
      editor.setValue(response.content || "# Override file\n", -1);
    }
  });
}

function showEditNameDialog(stackId, project, currentName) {
  swal2({
    title: 'Edit Stack Name',
    input: 'text',
    inputValue: currentName,
    inputPlaceholder: 'Enter new name',
    showCancelButton: true,
    confirmButtonText: 'Save',
    cancelButtonText: 'Cancel',
    inputValidator: (value) => {
      if (!value || !value.trim()) {
        return 'Name cannot be empty';
      }
    }
  }).then((result) => {
    if (result.value) {
      $.post(caURL, {action:'changeName', script:project, newName:result.value}, function(data) {
        window.location.reload();
      });
    }
  });
}

function showEditDescDialog(stackId, project) {
  var currentDesc = $('#desc' + stackId).html().replace(/<br>/g, '\n');
  
  swal2({
    title: 'Edit Description',
    input: 'textarea',
    inputValue: currentDesc,
    inputPlaceholder: 'Enter description',
    showCancelButton: true,
    confirmButtonText: 'Save',
    cancelButtonText: 'Cancel'
  }).then((result) => {
    if (result.value !== undefined) {
      var newDesc = result.value.replace(/\n/g, '<br>');
      $.post(caURL, {action:'changeDesc', script:project, newDesc:newDesc}, function(data) {
        $('#desc' + stackId).html(newDesc || 'No description');
      });
    }
  });
}

function editStackSettingsByProject(project) {
  $.post(caURL,{action:'getStackSettings',script:project},function(rawSettings) {
    if (rawSettings) {
      var settings = JSON.parse(rawSettings);
      if(settings.result == 'success') {
        var form = document.createElement("div");
        form.innerHTML =  `<div class="swal-text" style="font-weight: bold; padding-left: 0px; margin-top: 0px;">Icon URL</div>`;
        form.innerHTML += `<input type='url' id='icon_url' class='swal-content__input' placeholder='https://example.com/icon.png' value='${escapeAttr(settings.iconUrl || '')}'>`;
        form.innerHTML += `<div style="color:#888;font-size:0.85em;margin-top:4px;">Leave empty to use local icon file (icon.png in project folder)</div>`;
        form.innerHTML += `<br>`;
        form.innerHTML += `<div class="swal-text" style="font-weight: bold; padding-left: 0px; margin-top: 0px;">ENV File Path</div>`;
        form.innerHTML += `<input type='text' id='env_path' class='swal-content__input' pattern="(\/mnt\/.*\/.+)" oninput="this.reportValidity()" title="A path under /mnt/user/ or /mnt/cache/ or /mnt/pool/" placeholder='Default' value='${escapeAttr(settings.envPath || '')}'>`;
        swal2({
          title: "Stack Settings",
          content: form,
          buttons: true,
        }).then((inputValue) => {
          if (inputValue) {
            var new_env_path = document.getElementById("env_path").value;
            var new_icon_url = document.getElementById("icon_url").value;
            $.post(caURL,{action:'setStackSettings',envPath:new_env_path,iconUrl:new_icon_url,script:project},function(data) {
                var title = "Failed to set stack settings.";
                var message = "";
                var icon = "error";
                if (data) {
                  var response = JSON.parse(data);
                  if (response.result == "success") {
                    title = "Success";
                  }
                  message = response.message;
                  icon = response.result;
                }
                swal2({
                  title: title,
                  text: message,
                  icon: icon,
                }).then(() => {
                  location.reload();
                });
            });        
          }
        });
      }
    }
  });
}

function deleteStackByProject(project, projectName) {
  var element = document.createElement("div");
  element.innerHTML = "Are you sure you want to delete <font color='red'><b>"+escapeHtml(projectName)+"</b></font> (<font color='green'>"+escapeHtml(compose_root)+"/"+escapeHtml(project)+"</font>)?"; 
  swal2({
    content: element,
    title: "Delete Stack?",
    icon: "warning",
    buttons: true,
    dangerMode: true,
  }).then((willDelete) => {
    if (willDelete) {
      $.post(caURL,{action:'deleteStack',stackName:project},function(data) {
        if (data) {
          var response = JSON.parse(data);
          if (response.result == "warning") {
            swal2({
              title: "Files remain on disk.",
              text: response.message,
              icon: "warning",
            }).then(() => {
              location.reload();
            });
          } else {
            location.reload();
          }
        } else {
          location.reload();
        }
      });
    }
  });
}

// ============================================
// Expandable Stack Details Functions
// ============================================
function toggleStackDetails(stackId) {
  var $row = $('#stack-row-' + stackId);
  var $detailsRow = $('#details-row-' + stackId);
  var $expandIcon = $('#expand-icon-' + stackId);
  var project = $row.data('project');
  
  if (expandedStacks[stackId]) {
    // Collapse
    $detailsRow.slideUp(200);
    $expandIcon.removeClass('expanded');
    expandedStacks[stackId] = false;
  } else {
    // Expand
    $detailsRow.slideDown(200);
    $expandIcon.addClass('expanded');
    expandedStacks[stackId] = true;
    
    // Load container details if not cached or cache is stale
    loadStackContainerDetails(stackId, project);
  }
}

function loadStackContainerDetails(stackId, project) {
  var $container = $('#details-container-' + stackId);
  
  // Show loading state
  $container.html('<div class="stack-details-loading"><i class="fa fa-spinner fa-spin"></i> Loading container details...</div>');
  
  $.post(caURL, {action: 'getStackContainers', script: project}, function(data) {
    if (data) {
      var response = JSON.parse(data);
      if (response.result === 'success') {
        var containers = response.containers;
        
        // Merge update status from stackUpdateStatus if available
        if (stackUpdateStatus[project] && stackUpdateStatus[project].containers) {
          containers.forEach(function(container) {
            stackUpdateStatus[project].containers.forEach(function(update) {
              if (container.Name === update.container) {
                container.hasUpdate = update.hasUpdate;
                container.updateStatus = update.status;
                container.localSha = update.localSha || '';
                container.remoteSha = update.remoteSha || '';
              }
            });
          });
        }
        
        stackContainersCache[stackId] = containers;
        renderContainerDetails(stackId, containers, project);
      } else {
        // Escape error message to prevent XSS
        var errorMsg = escapeHtml(response.message || 'Failed to load container details');
        $container.html('<div class="stack-details-error"><i class="fa fa-exclamation-triangle"></i> ' + errorMsg + '</div>');
      }
    } else {
      $container.html('<div class="stack-details-error"><i class="fa fa-exclamation-triangle"></i> Failed to load container details</div>');
    }
  }).fail(function() {
    $container.html('<div class="stack-details-error"><i class="fa fa-exclamation-triangle"></i> Failed to load container details</div>');
  });
}

function renderContainerDetails(stackId, containers, project) {
  var $container = $('#details-container-' + stackId);
  
  if (!containers || containers.length === 0) {
    $container.html('<div class="stack-details-empty"><i class="fa fa-info-circle"></i> No containers found. Stack may not be running.</div>');
    return;
  }
  
  // Mini Docker table - matches Docker tab columns
  var html = '<table class="tablesorter shift" style="margin:0;font-size:0.95em;">';
  html += '<thead><tr>';
  html += '<th style="width:180px;">Container</th>';
  html += '<th>Update</th>';
  html += '<th>Version</th>';
  html += '<th>Network</th>';
  html += '<th>Container IP</th>';
  html += '<th>Container Port</th>';
  html += '<th>LAN IP:Port</th>';
  html += '</tr></thead>';
  html += '<tbody>';
  
  containers.forEach(function(container, idx) {
    var containerName = container.Name || container.Service || 'Unknown';
    var shortName = containerName.replace(/^[^-]+-/, ''); // Remove project prefix
    var image = container.Image || '';
    var imageParts = image.split(':');
    var imageTag = imageParts[1] || 'latest';
    var state = container.State || 'unknown';
    var containerId = (container.Id || containerName).substring(0, 12);
    var uniqueId = 'ct-' + stackId + '-' + idx;
    
    // Status like Docker tab
    var shape = state === 'running' ? 'play' : (state === 'paused' ? 'pause' : 'square');
    var statusText = state === 'running' ? 'started' : (state === 'paused' ? 'paused' : 'stopped');
    var color = state === 'running' ? 'green-text' : (state === 'paused' ? 'orange-text' : 'grey-text');
    var outerClass = state === 'running' ? 'started' : (state === 'paused' ? 'paused' : 'stopped');
    
    // Get networks and IPs
    var networkNames = [];
    var ipAddresses = [];
    if (container.Networks && container.Networks.length > 0) {
      container.Networks.forEach(function(net) {
        networkNames.push(net.name || '-');
        ipAddresses.push(net.ip || '-');
      });
    }
    if (networkNames.length === 0) { networkNames.push('-'); ipAddresses.push('-'); }
    
    // Format ports - separate container ports and mapped ports
    var containerPorts = [];
    var lanPorts = [];
    if (container.Ports && container.Ports.length > 0) {
      container.Ports.forEach(function(p) {
        // Format: "0.0.0.0:8080->80/tcp" or "80/tcp"
        var parts = p.split('->');
        if (parts.length === 2) {
          lanPorts.push(parts[0].replace('0.0.0.0:', ''));
          containerPorts.push(parts[1]);
        } else {
          containerPorts.push(p);
        }
      });
    }
    if (containerPorts.length === 0) containerPorts.push('-');
    if (lanPorts.length === 0) lanPorts.push('-');
    
    // WebUI
    var webui = '';
    if (container.WebUI) {
      webui = container.WebUI.replace(/\[IP\]/g, window.location.hostname);
      if (!isValidWebUIUrl(webui)) webui = '';
    }
    
    html += '<tr data-container="' + escapeAttr(containerName) + '" data-state="' + escapeAttr(state) + '" data-stackid="' + escapeAttr(stackId) + '">';
    
    // Container name column - matches Docker tab exactly
    html += '<td class="ct-name" style="width:180px;padding:8px;">';
    html += '<span class="outer ' + outerClass + '">';
    html += '<span id="' + uniqueId + '" class="hand" data-name="' + escapeAttr(containerName) + '" data-state="' + escapeAttr(state) + '" data-webui="' + escapeAttr(webui) + '" data-stackid="' + escapeAttr(stackId) + '">';
    // Use actual image like Docker tab - either container icon or default question.png
    var iconSrc = (container.Icon && (isValidWebUIUrl(container.Icon) || container.Icon.startsWith('data:image/'))) 
                  ? container.Icon 
                  : '/plugins/dynamix.docker.manager/images/question.png';
    html += '<img src="' + escapeAttr(iconSrc) + '" class="img" onerror="this.src=\'/plugins/dynamix.docker.manager/images/question.png\'">';
    html += '</span>';
    html += '<span class="inner"><span class="appname">' + escapeHtml(shortName) + '</span><br>';
    html += '<i class="fa fa-' + shape + ' ' + statusText + ' ' + color + '"></i><span class="state">' + statusText + '</span>';
    html += '</span></span>';
    html += '</td>';
    
    // Update column - shows update status for this container (like Docker tab)
    html += '<td class="ct-updatecolumn">';
    var ctHasUpdate = container.hasUpdate || false;
    var ctUpdateStatus = container.updateStatus || '';
    var ctLocalSha = container.localSha || '';
    var ctRemoteSha = container.remoteSha || '';
    
    if (ctHasUpdate) {
      // Update available - orange "update ready" style
      html += '<span class="orange-text" style="white-space:nowrap;"><i class="fa fa-flash fa-fw"></i> update ready</span>';
      if (ctLocalSha && ctRemoteSha) {
        html += '<div class="advanced" style="font-family:monospace;font-size:0.85em;">';
        html += '<span style="color:#f80;">' + escapeHtml(ctLocalSha) + '</span>';
        html += ' <i class="fa fa-arrow-right" style="margin:0 4px;color:#3c3;"></i> ';
        html += '<span style="color:#3c3;">' + escapeHtml(ctRemoteSha) + '</span>';
        html += '</div>';
      }
    } else if (ctUpdateStatus === 'up-to-date') {
      // No update - green "up-to-date" style
      html += '<span class="green-text" style="white-space:nowrap;"><i class="fa fa-check fa-fw"></i> up-to-date</span>';
      if (ctLocalSha) {
        html += '<div class="advanced" style="font-family:monospace;font-size:0.85em;color:#666;">' + escapeHtml(ctLocalSha) + '</div>';
      }
    } else {
      // Unknown/not checked - show "Compose" indicator
      html += '<span style="white-space:nowrap;color:#888;"><i class="fa fa-docker fa-fw"></i> not checked</span>';
    }
    html += '</td>';
    
    // Version (image tag)
    html += '<td><span class="docker_readmore" style="color:#f0a000;">' + escapeHtml(imageTag) + '</span></td>';
    
    // Network
    html += '<td style="white-space:nowrap;"><span class="docker_readmore">' + networkNames.map(escapeHtml).join('<br>') + '</span></td>';
    
    // Container IP
    html += '<td style="white-space:nowrap;"><span class="docker_readmore">' + ipAddresses.map(escapeHtml).join('<br>') + '</span></td>';
    
    // Container Port
    html += '<td style="white-space:nowrap;"><span class="docker_readmore">' + containerPorts.slice(0,3).map(escapeHtml).join('<br>') + (containerPorts.length > 3 ? '<br>...' : '') + '</span></td>';
    
    // LAN IP:Port
    html += '<td style="white-space:nowrap;"><span class="docker_readmore">' + lanPorts.slice(0,3).map(escapeHtml).join('<br>') + (lanPorts.length > 3 ? '<br>...' : '') + '</span></td>';
    
    html += '</tr>';
  });
  
  html += '</tbody></table>';
  
  $container.html(html);
  
  // Apply readmore to container details
  $container.find('.docker_readmore').readmore({maxHeight:32,moreLink:"<a href='#' style='text-align:center'><i class='fa fa-chevron-down'></i></a>",lessLink:"<a href='#' style='text-align:center'><i class='fa fa-chevron-up'></i></a>"});
  
  // Attach context menus to each container icon (like Docker tab)
  containers.forEach(function(container, idx) {
    var uniqueId = 'ct-' + stackId + '-' + idx;
    addComposeContainerContext(uniqueId);
  });
}

// Attach context menu to container icon (like Docker tab's addDockerContainerContext)
function addComposeContainerContext(elementId) {
  var $el = $('#' + elementId);
  var containerName = $el.data('name');
  var state = $el.data('state');
  var webui = $el.data('webui');
  var stackId = $el.data('stackid');
  var running = state === 'running';
  var paused = state === 'paused';
  
  var opts = [];
  context.settings({right: false, above: false});
  
  // WebUI (if running)
  if (running && webui) {
    opts.push({text: 'WebUI', icon: 'fa-globe', action: function(e) {
      e.preventDefault();
      window.open(webui, '_blank');
    }});
    opts.push({divider: true});
  }
  
  // Console (if running) - uses Unraid's openTerminal
  if (running) {
    opts.push({text: 'Console', icon: 'fa-terminal', action: function(e) {
      e.preventDefault();
      if (typeof openTerminal === 'function') {
        openTerminal('docker', containerName, '/bin/bash');
      } else {
        swal2({title: 'Console', text: 'Terminal not available', icon: 'info'});
      }
    }});
    opts.push({divider: true});
  }
  
  // Start/Stop/Pause/Resume
  if (running) {
    opts.push({text: 'Stop', icon: 'fa-stop', action: function(e) {
      e.preventDefault();
      containerAction(containerName, 'stop', stackId);
    }});
    opts.push({text: 'Pause', icon: 'fa-pause', action: function(e) {
      e.preventDefault();
      containerAction(containerName, 'pause', stackId);
    }});
    opts.push({text: 'Restart', icon: 'fa-refresh', action: function(e) {
      e.preventDefault();
      containerAction(containerName, 'restart', stackId);
    }});
  } else if (paused) {
    opts.push({text: 'Resume', icon: 'fa-play', action: function(e) {
      e.preventDefault();
      containerAction(containerName, 'unpause', stackId);
    }});
  } else {
    opts.push({text: 'Start', icon: 'fa-play', action: function(e) {
      e.preventDefault();
      containerAction(containerName, 'start', stackId);
    }});
  }
  
  opts.push({divider: true});
  
  // Logs - uses Unraid's openTerminal
  opts.push({text: 'Logs', icon: 'fa-navicon', action: function(e) {
    e.preventDefault();
    if (typeof openTerminal === 'function') {
      openTerminal('docker', containerName, '.log');
    } else {
      swal2({title: 'Logs', text: 'Terminal not available', icon: 'info'});
    }
  }});
  
  context.attach('#' + elementId, opts);
}

function containerAction(containerName, action, stackId) {
  // Show spinner on the container icon
  var $icon = $('[data-name="' + containerName + '"]').find('i,img').first();
  var originalClass = $icon.attr('class');
  if ($icon.is('i')) {
    $icon.removeClass().addClass('fa fa-refresh fa-spin');
  }
  
  $.post(caURL, {action: 'containerAction', container: containerName, containerAction: action}, function(data) {
    if (data) {
      var response = JSON.parse(data);
      if (response.result === 'success') {
        // Refresh the container details
        setTimeout(function() {
          var project = $('#stack-row-' + stackId).data('project');
          loadStackContainerDetails(stackId, project);
        }, 1000);
      } else {
        // Restore icon
        if ($icon.is('i')) $icon.removeClass().addClass(originalClass);
        swal2({
          title: 'Action Failed',
          text: escapeHtml(response.message) || 'Failed to ' + action + ' container',
          icon: 'error'
        });
      }
    }
  }).fail(function() {
    // Restore icon
    if ($icon.is('i')) $icon.removeClass().addClass(originalClass);
    swal2({
      title: 'Action Failed',
      text: 'Failed to ' + action + ' container',
      icon: 'error'
    });
  });
}

// Attach context menu to stack icon (like Docker tab's container context menu)
function addComposeStackContext(elementId) {
  var $el = $('#' + elementId);
  var stackId = $el.data('stackid');
  var project = $el.data('project');
  var projectName = $el.data('projectname');
  var isUp = $el.data('isup') == "1";
  var running = parseInt($el.data('running') || 0);
  
  var $row = $('#stack-row-' + stackId);
  var path = $row.data('path');
  var profiles = $row.data('profiles') || [];
  
  // Check if updates are available for this stack
  var hasUpdates = false;
  if (stackUpdateStatus[project] && stackUpdateStatus[project].hasUpdate) {
    hasUpdates = true;
  }
  
  var opts = [];
  context.settings({right: false, above: false});
  
  // Compose Up
  opts.push({text: isUp ? 'Compose Up (Recreate)' : 'Compose Up', icon: 'fa-play', action: function(e) {
    e.preventDefault();
    if (profiles.length > 0) {
      showProfileSelector('up', path, profiles);
    } else {
      ComposeUp(path);
    }
  }});
  
  // Compose Down (only if up)
  if (isUp) {
    opts.push({text: 'Compose Down', icon: 'fa-stop', action: function(e) {
      e.preventDefault();
      if (profiles.length > 0) {
        showProfileSelector('down', path, profiles);
      } else {
        ComposeDown(path);
      }
    }});
  }
  
  opts.push({divider: true});
  
  // Update Stack - disabled if no updates available
  var updateText = hasUpdates ? 'Update Stack' : 'Update Stack (no updates)';
  opts.push({text: updateText, icon: 'fa-cloud-download', disabled: !hasUpdates, action: function(e) {
    e.preventDefault();
    if (!hasUpdates) return;
    if (profiles.length > 0) {
      showProfileSelector('update', path, profiles);
    } else {
      UpdateStack(path);
    }
  }});
  
  opts.push({divider: true});
  
  // Edit Files
  opts.push({text: 'Edit Stack', icon: 'fa-edit', action: function(e) {
    e.preventDefault();
    openEditorModalByProject(project, projectName);
  }});
  
  // Settings
  opts.push({text: 'Settings', icon: 'fa-cog', action: function(e) {
    e.preventDefault();
    editStackSettingsByProject(project);
  }});
  
  // WebUI Labels
  opts.push({text: 'WebUI Labels', icon: 'fa-tag', action: function(e) {
    e.preventDefault();
    generateOverride(null, project);
  }});
  
  opts.push({divider: true});
  
  // View Logs
  opts.push({text: 'View Logs', icon: 'fa-navicon', action: function(e) {
    e.preventDefault();
    ComposeLogs(project);
  }});
  
  opts.push({divider: true});
  
  // Edit Name
  opts.push({text: 'Edit Name', icon: 'fa-pencil', action: function(e) {
    e.preventDefault();
    showEditNameDialog(stackId, project, projectName);
  }});
  
  // Edit Description
  opts.push({text: 'Edit Description', icon: 'fa-pencil-square-o', action: function(e) {
    e.preventDefault();
    showEditDescDialog(stackId, project);
  }});
  
  opts.push({divider: true});
  
  // Delete Stack (only if not running)
  if (!isUp) {
    opts.push({text: 'Delete Stack', icon: 'fa-trash', action: function(e) {
      e.preventDefault();
      deleteStackByProject(project, projectName);
    }});
  } else {
    opts.push({text: 'Delete Stack (Stop first)', icon: 'fa-trash', disabled: true});
  }
  
  context.attach('#' + elementId, opts);
}

// Event delegation for docker-style container actions
$(document).on('click', '.docker-action[data-action]', function(e) {
  e.preventDefault();
  var $action = $(this);
  var $row = $action.closest('.docker-row');
  var containerName = $row.data('container');
  var action = $action.data('action');
  if (containerName && action) {
    containerAction(containerName, action);
  }
});

// Close actions menu when clicking outside
$(document).on('click', function(e) {
  if (!$(e.target).closest('#stack-actions-modal, .stack-kebab-btn').length) {
    closeStackActionsMenu();
  }
});

// Close actions menu on escape key
$(document).on('keydown', function(e) {
  if (e.key === 'Escape') {
    closeStackActionsMenu();
  }
});

// Event delegation for container refresh button
$(document).on('click', '.container-refresh-btn[data-stack-id]', function(e) {
  e.preventDefault();
  var stackId = $(this).data('stack-id');
  var project = $('#stack-row-' + stackId).data('project');
  if (stackId && project) {
    loadStackContainerDetails(stackId, project);
  }
});

// Keyboard support for expand toggle (Enter/Space)
$(document).on('keydown', '.stack-expand-toggle', function(e) {
  if (e.key === 'Enter' || e.key === ' ') {
    e.preventDefault();
    $(this).click();
  }
});
</script>

<HTML>
<HEAD>
<style type="text/css">
.edit-stack-form .swal-footer {
  display: table;
  margin-left: auto;
  margin-right: auto;
}
.edit-stack-form .swal-footer .swal-button-container {
  display: table-row;
}
.edit-stack-form .swal-footer .swal-button-container .swal-button {
  width: 150px;
}
</style>
</HEAD>
<BODY>

<div class='editing' style="margin-bottom:34px;" hidden>
<!-- <center><b>Editing <?=$compose_root?>/<span id='editStackName'></span>/<span id='editStackFileName'></span></b><br> -->
<center><b>Editing <span id='editorFileName' data-stackname="" data-stackfilename=""></span></b><br>
<input type='button' value='Cancel' onclick='cancelEdit();'><input type='button' onclick='saveEdit();' value='Save Changes'><br>
<!-- <textarea class='editing' id='editStack' style='width:90%; height:500px; border-color:red; font-family:monospace;' ></textarea> -->
<div id='itemEditor' style='width:90%; height:500px; position: relative;'></div>
</center>
</div>

<span class='tipsterallowed' hidden></span>
<table id="compose_stacks" class="tablesorter shift">
<thead><tr>
  <th>Stack</th>
  <th>Update</th>
  <th>Containers</th>
  <th>Uptime</th>
  <th class="advanced">Description</th>
  <th class="advanced">Compose</th>
  <th class="advanced">Path</th>
  <th class="nine">Autostart</th>
</tr></thead>
<tbody id="compose_list">
<?=$o?>
</tbody>
</table>
<span class='tipsterallowed' hidden><input type='button' value='Add New Stack' onclick='addStack();'><input type='button' value='Check for Updates' onclick='checkAllUpdates();' id='checkUpdatesBtn' style='margin-left:10px;'><span><br>

<!-- Stack Actions Modal -->
<div id="stack-actions-modal" class="stack-actions-modal" style="display:none;">
  <div class="stack-actions-modal-header">
    <span class="stack-actions-modal-title">Stack Actions</span>
    <button class="stack-actions-modal-close" onclick="closeStackActionsMenu();">
      <i class="fa fa-times"></i>
    </button>
  </div>
  <div class="stack-actions-modal-body">
    <button class="stack-action-item" onclick="executeStackAction('up');">
      <i class="fa fa-play"></i> Compose Up
    </button>
    <button class="stack-action-item" onclick="executeStackAction('down');">
      <i class="fa fa-stop"></i> Compose Down
    </button>
    <button class="stack-action-item" onclick="executeStackAction('update');">
      <i class="fa fa-refresh"></i> Update Stack
    </button>
    <div class="stack-actions-divider"></div>
    <button class="stack-action-item" onclick="executeStackAction('logs');">
      <i class="fa fa-file-text-o"></i> View Logs
    </button>
    <div class="stack-actions-divider"></div>
    <button class="stack-action-item" onclick="executeStackAction('editFiles');">
      <i class="fa fa-edit"></i> Edit Files
    </button>
    <button class="stack-action-item" onclick="executeStackAction('editName');">
      <i class="fa fa-pencil"></i> Edit Name
    </button>
    <button class="stack-action-item" onclick="executeStackAction('editDesc');">
      <i class="fa fa-align-left"></i> Edit Description
    </button>
    <button class="stack-action-item" onclick="executeStackAction('uiLabels');">
      <i class="fa fa-tags"></i> UI Labels
    </button>
    <button class="stack-action-item" onclick="executeStackAction('settings');">
      <i class="fa fa-cog"></i> Stack Settings
    </button>
    <div class="stack-actions-divider"></div>
    <button class="stack-action-item stack-action-danger" onclick="executeStackAction('delete');">
      <i class="fa fa-trash"></i> Delete Stack
    </button>
  </div>
</div>
<div id="stack-actions-overlay" class="stack-actions-overlay" onclick="closeStackActionsMenu();" style="display:none;"></div>

</BODY>
</HTML>
