<?PHP
/**
 * Compose Manager Main Page
 * The stack list is loaded asynchronously via AJAX for better UX
 */

require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");

// Load plugin config
$cfg = parse_plugin_cfg($sName);
$autoCheckUpdates = ($cfg['AUTO_CHECK_UPDATES'] ?? 'false') === 'true';
$autoCheckDays = floatval($cfg['AUTO_CHECK_UPDATES_DAYS'] ?? '1');

// Note: Stack list is now loaded asynchronously via compose_list.php
// This improves page load time by deferring expensive docker commands
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

// Auto-check settings from config
var autoCheckUpdates = <?php echo json_encode($autoCheckUpdates); ?>;
var autoCheckDays = <?php echo json_encode($autoCheckDays); ?>;

// Timers for async operations (plugin-specific to avoid collision with Unraid's global timers)
var composeTimers = {};

// Load stack list asynchronously (namespaced to avoid conflict with Docker tab's loadlist)
function composeLoadlist() {
  // Show spinner after short delay to avoid flash on fast loads
  composeTimers.load = setTimeout(function(){
    $('div.spinner.fixed').show('slow');
  }, 500);
  
  $.get('/plugins/compose.manager/php/compose_list.php', function(data) {
    clearTimeout(composeTimers.load);
    
    // Insert the loaded content
    $('#compose_list').html(data);
    
    // Initialize UI components for the newly loaded content
    initStackListUI();
    
    // Hide spinner
    $('div.spinner.fixed').hide('slow');
    
    // Show buttons now that content is loaded
    $('input[type=button]').show();
  }).fail(function(xhr, status, error) {
    clearTimeout(composeTimers.load);
    $('div.spinner.fixed').hide('slow');
    $('#compose_list').html('<tr><td colspan="8" style="text-align:center;padding:20px;color:#c00;">Failed to load stack list. Please refresh the page.</td></tr>');
  });
}

// Initialize UI components after stack list is loaded
function initStackListUI() {
  // Initialize autostart switches - scope to compose_list to avoid conflict with Docker tab
  $('#compose_list .auto_start').switchButton({labels_placement:'right', on_label:"On", off_label:"Off"});
  $('#compose_list .auto_start').change(function(){
    var script = $(this).attr("data-scriptname");
    var auto = $(this).prop('checked');
    $.post(caURL, {action:'updateAutostart', script:script, autostart:auto});
  });
  
  // Initialize context menus for stack icons
  $('[id^="stack-"][data-stackid]').each(function() {
    addComposeStackContext(this.id);
  });
  
  // Apply readmore to descriptions - scope to compose_stacks
  $('#compose_stacks .docker_readmore').readmore({
    maxHeight: 32,
    moreLink: "<a href='#' style='text-align:center'><i class='fa fa-chevron-down'></i></a>",
    lessLink: "<a href='#' style='text-align:center'><i class='fa fa-chevron-up'></i></a>"
  });
  
  // Apply current view mode (advanced/basic) - scope to compose_stacks
  var advanced = $.cookie('compose_listview_mode') === 'advanced';
  if (advanced) {
    $('#compose_stacks .advanced').show();
    $('#compose_stacks .basic').hide();
  } else {
    $('#compose_stacks .advanced').hide();
    $('#compose_stacks .basic').show();
  }
  
  // Load saved update status after list is loaded
  loadSavedUpdateStatus();
}

$('head').append( $('<link rel="stylesheet" type="text/css" />').attr('href', '<?autov("/plugins/compose.manager/styles/comboButton.css");?>') );
$('head').append( $('<link rel="stylesheet" type="text/css" />').attr('href', '<?autov("/plugins/compose.manager/styles/editorModal.css");?>') );

function basename( path ) {
  return path.replace( /\\/g, '/' ).replace( /.*\//, '' );
}

function dirname( path ) {
  return path.replace( /\\/g, '/' ).replace( /\/[^\/]*$/, '' );
}

// Editor modal state
var editorModal = {
  editors: {},
  currentTab: 'compose',
  originalContent: {},
  modifiedTabs: new Set(),
  currentProject: null,
  validationTimeout: null,
  // Settings state
  originalSettings: {},
  modifiedSettings: new Set(),
  // Labels state
  originalLabels: {},
  modifiedLabels: new Set(),
  labelsData: null  // Stores the parsed compose and override data
};

// Debounce helper for validation
function debounceValidation(type, content) {
  if (editorModal.validationTimeout) {
    clearTimeout(editorModal.validationTimeout);
  }
  editorModal.validationTimeout = setTimeout(function() {
    validateYaml(type, content);
  }, 300);
}

// Calculate unRAID header offset dynamically
function updateModalOffset() {
  var headerOffset = 0;
  var header = document.getElementById('header');
  var menu = document.getElementById('menu');
  var tabs = document.querySelector('div.tabs');
  
  if (header) {
    headerOffset += header.offsetHeight;
  }
  if (menu) {
    headerOffset += menu.offsetHeight;
  }
  if (tabs) {
    headerOffset += tabs.offsetHeight;
  }
  
  // Add a small buffer
  headerOffset += 10;
  
  // Set CSS custom property
  document.documentElement.style.setProperty('--unraid-header-offset', headerOffset + 'px');
  var overlay = document.getElementById('editor-modal-overlay');
  if (overlay) {
    overlay.style.setProperty('--unraid-header-offset', headerOffset + 'px');
  }
}

// Initialize editor modal
function initEditorModal() {
  // Initialize Ace editors for compose and env tabs only
  ['compose', 'env'].forEach(function(type) {
    var editor = ace.edit('editor-' + type);
    editor.setTheme(aceTheme);
    editor.setShowPrintMargin(false);
    editor.setOptions({
      fontSize: '1.1rem',
      tabSize: 2,
      useSoftTabs: true,
      wrap: true
    });
    
    // Set mode based on type
    if (type === 'env') {
      editor.getSession().setMode('ace/mode/sh');
    } else {
      editor.getSession().setMode('ace/mode/yaml');
    }
    
    // Track modifications
    editor.on('change', function() {
      var currentContent = editor.getValue();
      var originalContent = editorModal.originalContent[type] || '';
      var tabEl = $('#editor-tab-' + type);
      
      if (currentContent !== originalContent) {
        editorModal.modifiedTabs.add(type);
        tabEl.addClass('modified');
      } else {
        editorModal.modifiedTabs.delete(type);
        tabEl.removeClass('modified');
      }
      
      updateSaveButtonState();
      updateTabModifiedState();
      debounceValidation(type, currentContent);
    });
    
    editorModal.editors[type] = editor;
  });
  
  // Initialize settings field change tracking
  $('#settings-name, #settings-description, #settings-icon-url, #settings-env-path, #settings-default-profile').on('input', function() {
    var fieldId = this.id.replace('settings-', '');
    var currentValue = $(this).val();
    var originalValue = editorModal.originalSettings[fieldId] || '';
    
    if (currentValue !== originalValue) {
      editorModal.modifiedSettings.add(fieldId);
    } else {
      editorModal.modifiedSettings.delete(fieldId);
    }
    
    updateSaveButtonState();
    updateTabModifiedState();
  });
  
  // Icon preview update with debounce
  var settingsIconDebounce = null;
  $('#settings-icon-url').on('input', function() {
    var $input = $(this);
    clearTimeout(settingsIconDebounce);
    settingsIconDebounce = setTimeout(function() {
      var url = $input.val().trim();
      if (url && (url.startsWith('http://') || url.startsWith('https://'))) {
        $('#settings-icon-preview-img').attr('src', url);
        $('#settings-icon-preview').show();
      } else {
        $('#settings-icon-preview').hide();
      }
    }, 300);
  });
  
  // Keyboard shortcuts - use namespaced event to avoid duplicates
  $(document).off('keydown.editorModal').on('keydown.editorModal', function(e) {
    if ($('#editor-modal-overlay').hasClass('active')) {
      // Ctrl+S or Cmd+S to save current
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        saveCurrentTab();
      }
      // Escape to close
      if (e.key === 'Escape') {
        e.preventDefault();
        closeEditorModal();
      }
      // Arrow key navigation for tabs
      if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        var $activeTab = $('.editor-tab.active');
        if ($activeTab.is(':focus') || $activeTab.parent().find(':focus').length) {
          e.preventDefault();
          var tabs = ['compose', 'env', 'labels', 'settings'];
          var currentIdx = tabs.indexOf(editorModal.currentTab);
          var newIdx;
          if (e.key === 'ArrowLeft') {
            newIdx = currentIdx > 0 ? currentIdx - 1 : tabs.length - 1;
          } else {
            newIdx = currentIdx < tabs.length - 1 ? currentIdx + 1 : 0;
          }
          switchTab(tabs[newIdx]);
          $('#editor-tab-' + tabs[newIdx]).focus();
        }
      }
      // Focus trapping
      if (e.key === 'Tab') {
        var $modal = $('#editor-modal-overlay');
        var $focusable = $modal.find('a, button, input, textarea, select, [tabindex]:not([tabindex="-1"])').filter(':visible:not(:disabled)');
        if ($focusable.length === 0) return;
        var first = $focusable[0];
        var last = $focusable[$focusable.length - 1];
        var activeElement = document.activeElement;
        
        if (!$.contains($modal[0], activeElement)) {
          e.preventDefault();
          first.focus();
          return;
        }
        
        if (!e.shiftKey && activeElement === last) {
          e.preventDefault();
          first.focus();
        } else if (e.shiftKey && activeElement === first) {
          e.preventDefault();
          last.focus();
        }
      }
    }
  });
}

// Switch between tabs (compose / env / labels / settings)
function switchTab(tabName) {
  var validTabs = ['compose', 'env', 'labels', 'settings'];
  if (validTabs.indexOf(tabName) === -1) {
    console.error('Invalid tab name: ' + tabName);
    return;
  }
  
  // Update tab buttons
  $('.editor-tab').removeClass('active').attr('aria-selected', 'false');
  $('#editor-tab-' + tabName).addClass('active').attr('aria-selected', 'true');
  
  // Update panels
  $('.editor-panel').removeClass('active');
  $('#editor-panel-' + tabName).addClass('active');
  
  editorModal.currentTab = tabName;
  
  // Resize and focus editor if switching to compose or env tab
  if ((tabName === 'compose' || tabName === 'env') && editorModal.editors[tabName]) {
    editorModal.editors[tabName].resize();
    editorModal.editors[tabName].focus();
  }
  
  // Load labels data if switching to labels tab for the first time
  if (tabName === 'labels' && !editorModal.labelsData) {
    loadLabelsData();
  }
}

// Update the modified indicator on tabs
function updateTabModifiedState() {
  // Compose tab
  if (editorModal.modifiedTabs.has('compose')) {
    $('#editor-tab-compose').addClass('modified');
  } else {
    $('#editor-tab-compose').removeClass('modified');
  }
  
  // Env tab
  if (editorModal.modifiedTabs.has('env')) {
    $('#editor-tab-env').addClass('modified');
  } else {
    $('#editor-tab-env').removeClass('modified');
  }
  
  // Labels tab
  if (editorModal.modifiedLabels.size > 0) {
    $('#editor-tab-labels').addClass('modified');
  } else {
    $('#editor-tab-labels').removeClass('modified');
  }
  
  // Settings tab
  if (editorModal.modifiedSettings.size > 0) {
    $('#editor-tab-settings').addClass('modified');
  } else {
    $('#editor-tab-settings').removeClass('modified');
  }
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

// Load saved update status from server (called on page load)
// If auto-check is enabled and interval has elapsed, trigger a fresh check
function loadSavedUpdateStatus() {
  $.post(caURL, {action: 'getSavedUpdateStatus'}, function(data) {
    if (data) {
      try {
        var response = JSON.parse(data);
        if (response.result === 'success' && response.stacks) {
          stackUpdateStatus = response.stacks;
          
          // Update the UI for each stack with saved status
          for (var stackName in response.stacks) {
            var stackInfo = response.stacks[stackName];
            updateStackUpdateUI(stackName, stackInfo);
          }
          
          // Enable/disable Update All button based on saved status
          updateUpdateAllButton();
          
          // Check if auto-check should run based on interval
          if (autoCheckUpdates) {
            checkAutoUpdateIfNeeded(response.stacks);
          }
        } else if (autoCheckUpdates) {
          // No saved status, run check immediately if auto-check enabled
          checkAllUpdates();
        }
      } catch(e) {
        console.error('Failed to load saved update status:', e);
        if (autoCheckUpdates) {
          // On error, run check if auto-check enabled
          checkAllUpdates();
        }
      }
    } else if (autoCheckUpdates) {
      // No data, run check if auto-check enabled
      checkAllUpdates();
    }
  });
}

// Check if auto-update check is needed based on lastChecked timestamp
function checkAutoUpdateIfNeeded(stacks) {
  if (!autoCheckUpdates) return;
  
  var now = Math.floor(Date.now() / 1000); // Current time in seconds
  var intervalSeconds = autoCheckDays * 24 * 60 * 60; // Convert days to seconds
  var needsCheck = true;
  
  // Find the most recent lastChecked timestamp across all stacks
  var latestCheck = 0;
  for (var stackName in stacks) {
    if (stacks[stackName].lastChecked && stacks[stackName].lastChecked > latestCheck) {
      latestCheck = stacks[stackName].lastChecked;
    }
  }
  
  // If we have a lastChecked time and it's within the interval, don't check
  if (latestCheck > 0 && (now - latestCheck) < intervalSeconds) {
    needsCheck = false;
    console.log('Auto-check: Last check was ' + Math.round((now - latestCheck) / 60) + ' minutes ago, interval is ' + Math.round(intervalSeconds / 60) + ' minutes. Skipping.');
  }
  
  if (needsCheck) {
    console.log('Auto-check: Running automatic update check...');
    checkAllUpdates();
  }
}

// Check for updates for all stacks
function checkAllUpdates() {
  $('#checkUpdatesBtn').prop('disabled', true).val('Checking...');
  $('#updateAllBtn').prop('disabled', true);
  
  // Show checking indicator only on running stack update columns (not stopped ones)
  $('#compose_stacks tr.compose-sortable').each(function() {
    var $row = $(this);
    var isRunning = $row.find('.state').text().indexOf('started') !== -1 || 
                   $row.find('.state').text().indexOf('partial') !== -1;
    if (isRunning) {
      var $updateCell = $row.find('.compose-updatecolumn');
      $updateCell.html('<span style="color:#267CA8"><i class="fa fa-refresh fa-spin"></i> checking...</span>');
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
          
          // Enable/disable Update All button based on available updates
          updateUpdateAllButton();
        }
      } catch(e) {
        console.error('Failed to parse update check response:', e);
      }
    }
    $('#checkUpdatesBtn').prop('disabled', false).val('Check for Updates');
  }).fail(function() {
    $('#checkUpdatesBtn').prop('disabled', false).val('Check for Updates');
    $('#updateAllBtn').prop('disabled', true);
    // Reset update columns to not checked state - scope to compose_stacks
    $('#compose_stacks .compose-updatecolumn').each(function() {
      var $cell = $(this);
      if (!$cell.find('.grey-text').length || $cell.find('.fa-docker').length === 0) {
        // Only reset running stacks (not the "stopped" ones)
        $cell.html('<span class="grey-text" style="white-space:nowrap;cursor:default;"><i class="fa fa-exclamation-circle fa-fw"></i> check failed</span>');
      }
    });
  });
}

// Check how many stacks have updates and enable/disable the Update All button
function updateUpdateAllButton() {
  var stacksWithUpdates = 0;
  for (var stackName in stackUpdateStatus) {
    var stackInfo = stackUpdateStatus[stackName];
    if (stackInfo.hasUpdate && stackInfo.isRunning) {
      stacksWithUpdates++;
    }
  }
  $('#updateAllBtn').prop('disabled', stacksWithUpdates === 0);
}

// Update All Stacks - updates all stacks that have pending updates
function updateAllStacks() {
  var autostartOnly = $('#autostartOnlyToggle').is(':checked');
  var stacks = [];
  
  // Collect all stacks with updates
  for (var stackName in stackUpdateStatus) {
    var stackInfo = stackUpdateStatus[stackName];
    if (stackInfo.hasUpdate && stackInfo.isRunning) {
      var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + stackName + '"]');
      if ($stackRow.length === 0) continue;
      
      var autostart = $stackRow.find('.autostart').is(':checked');
      
      // Skip if autostart only mode and autostart is not enabled
      if (autostartOnly && !autostart) continue;
      
      var path = $stackRow.data('path');
      var projectName = $stackRow.data('projectname');
      
      stacks.push({
        project: stackName,
        projectName: projectName,
        path: path
      });
    }
  }
  
  if (stacks.length === 0) {
    swal({
      title: 'No Updates Available',
      text: autostartOnly ? 'No stacks with Autostart enabled have updates available.' : 'No stacks have updates available.',
      type: 'info'
    });
    return;
  }
  
  var stackNames = stacks.map(function(s) { return escapeHtml(s.projectName); }).join('<br>');
  var title = autostartOnly ? 'Update Autostart Stacks?' : 'Update All Stacks?';
  var confirmText = 'Yes, update ' + stacks.length + ' stack' + (stacks.length > 1 ? 's' : '');
  
  swal({
    title: title,
    html: true,
    text: '<div style="text-align:left;max-width:400px;margin:0 auto;"><p>The following stacks will be updated:</p><div style="background:rgba(0,0,0,0.2);padding:10px;border-radius:4px;max-height:200px;overflow-y:auto;margin:10px 0;">' + stackNames + '</div><p style="color:#f80;"><i class="fa fa-warning"></i> This will pull new images and recreate containers.</p></div>',
    type: 'warning',
    showCancelButton: true,
    confirmButtonText: confirmText,
    cancelButtonText: 'Cancel'
  }, function(confirmed) {
    if (confirmed) {
      executeUpdateAllStacks(stacks);
    }
  });
}

function executeUpdateAllStacks(stacks) {
  var height = 800;
  var width = 1200;
  
  // Create a list of paths to update
  var paths = stacks.map(function(s) { return s.path; });
  
  $.post(compURL, {action:'composeUpdateMultiple', paths:JSON.stringify(paths)}, function(data) {
    if (data) {
      openBox(data, 'Update All Stacks', height, width, true);
    }
  });
}

// Update UI for a single stack's update status
function updateStackUpdateUI(stackName, stackInfo) {
  // Find the stack row by project name (scoped to compose_stacks to avoid Docker tab conflicts)
  var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + stackName + '"]');
  if ($stackRow.length === 0) return;
  
  var stackId = $stackRow.attr('id').replace('stack-row-', '');
  var $updateCell = $stackRow.find('.compose-updatecolumn');
  
  // Check if the stack is running - use server response or DOM state
  var isRunning = stackInfo.isRunning;
  if (isRunning === undefined) {
    // Fallback to DOM state check
    var stateText = $stackRow.find('.state').text();
    isRunning = stateText.indexOf('started') !== -1 || stateText.indexOf('partial') !== -1;
  }
  
  if (!isRunning) {
    // Stack is not running - show stopped status
    $updateCell.html('<span class="grey-text" style="white-space:nowrap;"><i class="fa fa-stop fa-fw"></i> stopped</span>');
    return;
  }
  
  // Count updates and pinned containers
  var updateCount = 0;
  var pinnedCount = 0;
  var totalContainers = stackInfo.containers ? stackInfo.containers.length : 0;
  
  if (stackInfo.containers) {
    stackInfo.containers.forEach(function(ct) {
      if (ct.hasUpdate) updateCount++;
      if (ct.isPinned) pinnedCount++;
    });
  }
  
  // Update the stack row's update column (match Docker tab style)
  if (updateCount > 0) {
    // Updates available - orange "update ready" style with clickable link and SHA info
    var updateHtml = '<a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + escapeAttr(stackName) + '\', \'' + escapeAttr(stackId) + '\');">';
    updateHtml += '<span class="orange-text" style="white-space:nowrap;"><i class="fa fa-flash fa-fw"></i> ' + updateCount + ' update' + (updateCount > 1 ? 's' : '') + '</span>';
    updateHtml += '</a>';
    
    // Show first container's SHA diff if only one update, or indicate multiple
    if (stackInfo.containers) {
      var updatesWithSha = stackInfo.containers.filter(function(ct) { 
        return ct.hasUpdate && ct.localSha && ct.remoteSha; 
      });
      if (updatesWithSha.length === 1) {
        // Single update - show the SHA diff inline
        var ct = updatesWithSha[0];
        updateHtml += '<div style="font-family:monospace;font-size:0.8em;margin-top:2px;">';
        updateHtml += '<span style="color:#f80;">' + escapeHtml(ct.localSha) + '</span>';
        updateHtml += ' <i class="fa fa-arrow-right" style="margin:0 2px;color:#3c3;font-size:0.9em;"></i> ';
        updateHtml += '<span style="color:#3c3;">' + escapeHtml(ct.remoteSha) + '</span>';
        updateHtml += '</div>';
      } else if (updatesWithSha.length > 1) {
        // Multiple updates - show expand hint
        updateHtml += '<div class="advanced" style="font-size:0.8em;color:#999;margin-top:2px;">Expand for details</div>';
      }
    }
    
    // Also show pinned count if any containers are pinned
    if (pinnedCount > 0) {
      updateHtml += '<div style="font-size:0.8em;color:#17a2b8;margin-top:2px;"><i class="fa fa-thumb-tack fa-fw"></i> ' + pinnedCount + ' pinned</div>';
    }
    $updateCell.html(updateHtml);
  } else if (totalContainers > 0) {
    // No updates - check if all are pinned or up-to-date
    if (pinnedCount > 0 && pinnedCount === totalContainers) {
      // All containers are pinned
      var html = '<span class="cyan-text" style="white-space:nowrap;"><i class="fa fa-thumb-tack fa-fw"></i> all pinned</span>';
      $updateCell.html(html);
    } else if (pinnedCount > 0) {
      // Some containers pinned, rest up-to-date
      var html = '<span class="green-text" style="white-space:nowrap;"><i class="fa fa-check fa-fw"></i> up-to-date</span>';
      html += '<div style="font-size:0.8em;color:#17a2b8;margin-top:2px;"><i class="fa fa-thumb-tack fa-fw"></i> ' + pinnedCount + ' pinned</div>';
      html += '<div class="advanced"><a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + escapeAttr(stackName) + '\', \'' + escapeAttr(stackId) + '\');"><span style="white-space:nowrap;"><i class="fa fa-cloud-download fa-fw"></i> force update</span></a></div>';
      $updateCell.html(html);
    } else {
      // No updates, no pinned - green "up-to-date" style (like Docker tab)
      // Basic view: just shows up-to-date
      // Advanced view: shows force update link
      var html = '<span class="green-text" style="white-space:nowrap;"><i class="fa fa-check fa-fw"></i> up-to-date</span>';
      html += '<div class="advanced"><a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + escapeAttr(stackName) + '\', \'' + escapeAttr(stackId) + '\');"><span style="white-space:nowrap;"><i class="fa fa-cloud-download fa-fw"></i> force update</span></a></div>';
      $updateCell.html(html);
    }
  } else {
    // No containers found - show pull updates as clickable (for stacks that aren't running)
    $updateCell.html('<a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + escapeAttr(stackName) + '\', \'' + escapeAttr(stackId) + '\');"><i class="fa fa-cloud-download fa-fw"></i> pull updates</a>');
  }
  
  // Rebuild context menus to reflect update status (only target icon spans with data-stackid, not the row)
  $('[id^="stack-"][data-stackid][data-project="' + stackName + '"]').each(function() {
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
          cached.isPinned = updated.isPinned || false;
          cached.pinnedDigest = updated.pinnedDigest || '';
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
  var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + stackName + '"]');
  if ($stackRow.length === 0) return;
  
  var $updateCell = $stackRow.find('.compose-updatecolumn');
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

// Apply advanced/basic view based on cookie (used after async load)
// Scoped to compose_stacks to avoid affecting Docker tab when tabs are joined
function applyListView() {
  var advanced = $.cookie('compose_listview_mode') === 'advanced';
  if (advanced) {
    $('#compose_stacks .advanced').show();
    $('#compose_stacks .basic').hide();
  } else {
    $('#compose_stacks .advanced').hide();
    $('#compose_stacks .basic').show();
  }
  // Apply readmore to descriptions
  $('#compose_stacks .docker_readmore').readmore({maxHeight:32,moreLink:"<a href='#' style='text-align:center'><i class='fa fa-chevron-down'></i></a>",lessLink:"<a href='#' style='text-align:center'><i class='fa fa-chevron-up'></i></a>"});
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
  
  // Add Advanced View toggle (like Docker tab)
  // Use compose-specific class to avoid conflict with Docker tab's advancedview when tabs are joined
  $(".tabs").append('<span class="status compose-view-toggle"><span><input type="checkbox" class="compose-advancedview"></span></span>');
  $('.compose-advancedview').switchButton({labels_placement:'left', on_label:'Advanced View', off_label:'Basic View', checked:$.cookie('compose_listview_mode')==='advanced'});
  $('.compose-advancedview').change(function(){
    // Use instant toggle to avoid text wrapping issues during animation
    // Scope to compose_stacks table to avoid affecting Docker tab
    $('#compose_stacks .advanced').toggle();
    $('#compose_stacks .basic').toggle();
    $.cookie('compose_listview_mode', $('.compose-advancedview').is(':checked') ? 'advanced' : 'basic', {expires:3650});
  });
  
  // Set up MutationObserver to detect when ebox (progress dialog) closes
  // This is used to trigger update check after an update operation completes
  var eboxObserver = new MutationObserver(function(mutations) {
    mutations.forEach(function(mutation) {
      if (mutation.removedNodes.length > 0) {
        mutation.removedNodes.forEach(function(node) {
          // Check if the removed node is the ebox or contains it
          if (node.id === 'ebox' || (node.querySelector && node.querySelector('#ebox'))) {
            if (pendingUpdateCheck) {
              pendingUpdateCheck = false;
              // Delay slightly to let page state settle
              setTimeout(function() {
                console.log('Update completed, running check for updates...');
                checkAllUpdates();
              }, 1000);
            }
          }
        });
      }
    });
  });
  
  // Start observing the body for changes (ebox gets added/removed from body)
  eboxObserver.observe(document.body, { childList: true, subtree: true });
  
  // Load the stack list asynchronously (like Docker tab)
  // This defers the expensive docker commands to after the page renders
  composeLoadlist();
});

function addStack() {
  swal({
    title: "Add New Compose Stack",
    text: "Enter a name for your new stack:",
    type: "input",
    inputPlaceholder: "Stack Name",
    showCancelButton: true,
    closeOnConfirm: false,
    inputValidator: function(value) {
      if (!value || !value.trim()) {
        return "Please enter a stack name";
      }
    }
  }, function(inputValue) {
    if (inputValue === false) return; // User cancelled
    
    var new_stack_name = inputValue.trim();
    if (!new_stack_name) {
      swal.showInputError("Please enter a stack name");
      return false;
    }
    
    $.post(
      caURL,
      {action:'addStack', stackName:new_stack_name},
      function(data) {
        if (data) {
          var response = JSON.parse(data);
          if (response.result == "success") {
            // Close the swal dialog and open editor
            swal.close();
            // Open the editor modal for the newly created stack
            openEditorModalByProject(response.project, response.projectName);
            // Refresh the list in the background
            composeLoadlist();
          } else {
            swal({
              title: "Failed to create stack",
              text: response.message || "An error occurred",
              type: "error"
            });
          }
        } else {
          swal({
            title: "Failed to create stack",
            text: "No response from server",
            type: "error"
          });
        }
      }
    ).fail(function() {
      swal({
        title: "Failed to create stack",
        text: "Request failed",
        type: "error"
      });
    });
  });
}

function deleteStack(myID) {
  var stackName = $("#"+myID).attr("data-scriptname");
  var project = $("#"+myID).attr("data-namename");
  var msgHtml = "Are you sure you want to delete <font color='red'><b>"+project+"</b></font> (<font color='green'>"+compose_root+"/"+stackName+"</font>)?"; 
  swal({
    title: "Delete Stack?",
    text: msgHtml,
    html: true,
    type: "warning",
    showCancelButton: true,
    confirmButtonText: "Delete",
    cancelButtonText: "Cancel"
  }, function(confirmed) {
    if (confirmed) {
      $.post(caURL,{action:'deleteStack',stackName:stackName},function(data) {
        if (data) {
          var response = JSON.parse(data);
          if (response.result == "warning") {
            swal({
              title: "Files remain on disk.",
              text: response.message,
              type: "warning"
            }, function() {
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

// Opens editor modal directly using myID element (from tooltipster)
function editStack(myID) {
  $("#"+myID).tooltipster("close");
  var project = $("#"+myID).attr("data-scriptname");
  var projectName = $("#"+myID).attr("data-namename");
  openEditorModalByProject(project, projectName);
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
            swal({
              title: "Edit Stack UI Labels",
              text: html,
              html: true,
              showCancelButton: true,
              confirmButtonText: "Save",
              cancelButtonText: "Cancel"
            }, function(confirmed) {
              if(confirmed) {
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
                    swal({
                      title: "Failed to update labels.",
                      type: "error"
                    });
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
            swal({
              title: "Failed to update profiles.",
              type: "error"
            });
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
        var formHtml = `<div class="swal-text" style="font-weight: bold; padding-left: 0px; margin-top: 0px;">ENV File Path</div>`;
        formHtml += `<br>`;
        formHtml += `<input type='text' id='env_path' class='swal-content__input' pattern="(\/mnt\/.*\/.+)" oninput="this.reportValidity()" title="A path under /mnt/user/ or /mnt/cache/ or /mnt/pool/" placeholder=Default value='${rawEnvPath.content}'>`;
        swal({
          title: "Stack Settings",
          text: formHtml,
          html: true,
          showCancelButton: true,
          confirmButtonText: "Save",
          closeOnConfirm: false
        }, function(confirmed) {
          if (confirmed) {
            var new_env_path = document.getElementById("env_path").value;
            $.post(caURL,{action:'setEnvPath',envPath:new_env_path,script:project},function(data) {
                var title = "Failed to set stack settings.";
                var message = "";
                var type = "error";
                if (data) {
                  var response = JSON.parse(data);
                  if (response.result == "success") {
                    title = "Success";
                  }
                  message = response.message;
                  type = response.result;
                }
                swal({
                  title: title,
                  text: message,
                  type: type
                }, function() {
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

// Flag to track if an update was just performed
var pendingUpdateCheck = false;

function UpdateStackConfirmed(path, profile="") {
  var height = 800;
  var width = 1200;

  // Set flag to trigger update check when dialog closes
  pendingUpdateCheck = true;

  $.post(compURL,{action:'composeUpPullBuild',path:path,profile:profile},function(data) {
    if (data) {
      openBox(data,"Update Stack "+basename(path),height,width,true);
    }
  })
}

function UpdateStack(path, profile="") {
  showStackActionDialog('update', path, profile);
}

// Start All Stacks function
function startAllStacks() {
  var autostartOnly = $('#autostartOnlyToggle').is(':checked');
  var stacks = [];
  
  // Collect all stacks from the table
  $('#compose_stacks tr.compose-sortable').each(function() {
    var $row = $(this);
    var project = $row.data('project');
    var projectName = $row.data('projectname');
    var path = $row.data('path');
    var isUp = $row.data('isup');
    var autostart = $row.find('.autostart').is(':checked');
    
    // Skip if autostart only mode and autostart is not enabled
    if (autostartOnly && !autostart) return;
    
    // Only include stopped stacks
    var $stateEl = $row.find('.state');
    var stateText = $stateEl.text();
    if (stateText === 'stopped' || !isUp) {
      stacks.push({
        project: project,
        projectName: projectName,
        path: path
      });
    }
  });
  
  if (stacks.length === 0) {
    swal({
      title: 'No Stacks to Start',
      text: autostartOnly ? 'No stopped stacks with Autostart enabled found.' : 'No stopped stacks found.',
      type: 'info'
    });
    return;
  }
  
  var stackNames = stacks.map(function(s) { return escapeHtml(s.projectName); }).join('<br>');
  var title = autostartOnly ? 'Start Autostart Stacks?' : 'Start All Stacks?';
  var confirmText = autostartOnly ? 'Yes, start ' + stacks.length + ' autostart stack' + (stacks.length > 1 ? 's' : '') : 'Yes, start ' + stacks.length + ' stack' + (stacks.length > 1 ? 's' : '');
  
  swal({
    title: title,
    html: true,
    text: '<div style="text-align:left;max-width:400px;margin:0 auto;"><p>The following stacks will be started:</p><div style="background:rgba(0,0,0,0.2);padding:10px;border-radius:4px;max-height:200px;overflow-y:auto;margin:10px 0;">' + stackNames + '</div></div>',
    type: 'warning',
    showCancelButton: true,
    confirmButtonText: confirmText,
    cancelButtonText: 'Cancel'
  }, function(confirmed) {
    if (confirmed) {
      executeStartAllStacks(stacks);
    }
  });
}

function executeStartAllStacks(stacks) {
  var height = 800;
  var width = 1200;
  
  // Create a list of paths to start
  var paths = stacks.map(function(s) { return s.path; });
  
  $.post(compURL, {action:'composeUpMultiple', paths:JSON.stringify(paths)}, function(data) {
    if (data) {
      openBox(data, 'Start All Stacks', height, width, true);
    }
  });
}

// Stop All Stacks function
function stopAllStacks() {
  var autostartOnly = $('#autostartOnlyToggle').is(':checked');
  var stacks = [];
  
  // Collect all stacks from the table
  $('#compose_stacks tr.compose-sortable').each(function() {
    var $row = $(this);
    var project = $row.data('project');
    var projectName = $row.data('projectname');
    var path = $row.data('path');
    var isUp = $row.data('isup');
    var autostart = $row.find('.autostart').is(':checked');
    
    // Skip if autostart only mode and autostart is not enabled
    if (autostartOnly && !autostart) return;
    
    // Only include running stacks
    var $stateEl = $row.find('.state');
    var stateText = $stateEl.text();
    if (stateText !== 'stopped' && isUp) {
      stacks.push({
        project: project,
        projectName: projectName,
        path: path
      });
    }
  });
  
  if (stacks.length === 0) {
    swal({
      title: 'No Stacks to Stop',
      text: autostartOnly ? 'No running stacks with Autostart enabled found.' : 'No running stacks found.',
      type: 'info'
    });
    return;
  }
  
  var stackNames = stacks.map(function(s) { return escapeHtml(s.projectName); }).join('<br>');
  var title = autostartOnly ? 'Stop Autostart Stacks?' : 'Stop All Stacks?';
  var confirmText = autostartOnly ? 'Yes, stop ' + stacks.length + ' autostart stack' + (stacks.length > 1 ? 's' : '') : 'Yes, stop ' + stacks.length + ' stack' + (stacks.length > 1 ? 's' : '');
  
  swal({
    title: title,
    html: true,
    text: '<div style="text-align:left;max-width:400px;margin:0 auto;"><p>The following stacks will be stopped:</p><div style="background:rgba(0,0,0,0.2);padding:10px;border-radius:4px;max-height:200px;overflow-y:auto;margin:10px 0;">' + stackNames + '</div><p style="color:#f80;margin-top:10px;"><i class="fa fa-exclamation-triangle"></i> Containers will be stopped and removed. Data in volumes will be preserved.</p></div>',
    type: 'warning',
    showCancelButton: true,
    confirmButtonText: confirmText,
    cancelButtonText: 'Cancel'
  }, function(confirmed) {
    if (confirmed) {
      executeStopAllStacks(stacks);
    }
  });
}

function executeStopAllStacks(stacks) {
  var height = 800;
  var width = 1200;
  
  // Create a list of paths to stop
  var paths = stacks.map(function(s) { return s.path; });
  
  $.post(compURL, {action:'composeDownMultiple', paths:JSON.stringify(paths)}, function(data) {
    if (data) {
      openBox(data, 'Stop All Stacks', height, width, true);
    }
  });
}

// Helper to merge update status into containers array
function mergeUpdateStatus(containers, project) {
  if (!containers || !stackUpdateStatus[project] || !stackUpdateStatus[project].containers) {
    return containers;
  }
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
  return containers;
}

// Unified stack action dialog - handles up, down, and update actions
function showStackActionDialog(action, path, profile) {
  var stackName = basename(path);
  var project = stackName;
  
  // Find the stack row (scoped to compose_stacks)
  var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]');
  var stackId = '';
  if ($stackRow.length > 0) {
    stackId = $stackRow.attr('id').replace('stack-row-', '');
  }
  
  // Check if we have cached container data
  if (stackId && stackContainersCache[stackId] && stackContainersCache[stackId].length > 0) {
    // Merge update status into cached data before rendering
    var containers = mergeUpdateStatus(stackContainersCache[stackId], project);
    renderStackActionDialog(action, stackName, path, profile, containers);
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
      // Merge update status into freshly fetched data
      containers = mergeUpdateStatus(containers, project);
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

function ComposePull(path, profile="") {
  var height = 800;
  var width = 1200;
  $.post(compURL,{action:'composePull',path:path,profile:profile},function(data) {
    if (data) {
      openBox(data,"Stack "+basename(path)+" Pull",height,width,true);
    }
  })
}

function ComposeLogs(pathOrProject, profile="") {
  var height = 800;
  var width = 1200;
  // Support both project name (legacy) and path
  var path = pathOrProject.includes('/') ? pathOrProject : compose_root + "/" + pathOrProject;
  $.post(compURL,{action:'composeLogs',path:path,profile:profile},function(data) {
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
  var profileSupportedActions = ['up', 'down', 'update', 'pull', 'logs'];
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
    case 'pull':
      ComposePull(path);
      break;
    case 'logs':
      ComposeLogs(path);
      break;
    case 'edit':
      openEditorModalByProject(project, projectName);
      break;
    case 'delete':
      if (!isUp) {
        deleteStackByProject(project, projectName);
      }
      break;
  }
}

function showProfileSelector(action, path, profiles) {
  var actionNames = {
    'up': 'Compose Up',
    'down': 'Compose Down',
    'update': 'Update Stack',
    'pull': 'Pull Images',
    'logs': 'View Logs'
  };
  
  // Build profile selection HTML with checkboxes for multi-select
  var profileHtml = '<div style="text-align: left;">';
  profileHtml += '<div style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid rgba(128,128,128,0.3);">';
  profileHtml += '<label style="font-weight:bold;"><input type="checkbox" id="profile_all" checked onchange="toggleAllProfiles(this)"> All Services (no profile filter)</label>';
  profileHtml += '</div>';
  profileHtml += '<div id="profile_list">';
  profiles.forEach(function(profile) {
    profileHtml += '<label style="display:block;margin:5px 0;"><input type="checkbox" class="profile_checkbox" value="' + escapeHtml(profile) + '" disabled> ' + escapeHtml(profile) + '</label>';
  });
  profileHtml += '</div>';
  profileHtml += '<div style="margin-top:10px;font-size:0.9em;color:#888;"><i class="fa fa-info-circle"></i> Select multiple profiles to include services from each.</div>';
  profileHtml += '</div>';
  
  swal({
    title: "Select Profiles",
    text: "Choose which profiles to use for " + actionNames[action] + "<br><br>" + profileHtml,
    html: true,
    showCancelButton: true,
    confirmButtonText: "Continue",
    cancelButtonText: "Cancel"
  }, function(confirmed) {
    if (confirmed) {
      var selectedProfiles = [];
      if (!$('#profile_all').is(':checked')) {
        $('.profile_checkbox:checked').each(function() {
          selectedProfiles.push($(this).val());
        });
      }
      // Join profiles with comma for multi-profile support
      var profileStr = selectedProfiles.join(',');
      switch(action) {
        case 'up':
          ComposeUp(path, profileStr);
          break;
        case 'down':
          ComposeDown(path, profileStr);
          break;
        case 'update':
          UpdateStack(path, profileStr);
          break;
        case 'pull':
          ComposePull(path, profileStr);
          break;
        case 'logs':
          ComposeLogs(path, profileStr);
          break;
      }
    }
  });
}

// Toggle profile checkboxes when "All Services" is checked/unchecked
function toggleAllProfiles(checkbox) {
  var disabled = checkbox.checked;
  $('.profile_checkbox').prop('disabled', disabled).prop('checked', false);
}

function openEditorModalByProject(project, projectName, initialTab) {
  editorModal.currentProject = project;
  editorModal.modifiedTabs = new Set();
  editorModal.modifiedSettings = new Set();
  editorModal.modifiedLabels = new Set();
  editorModal.originalContent = {};
  editorModal.originalSettings = {};
  editorModal.originalLabels = {};
  editorModal.labelsData = null;
  
  // Reset all tabs to unmodified state
  $('.editor-tab').removeClass('modified active');
  $('.editor-main-tab').removeClass('modified active');
  $('.editor-container').removeClass('active');
  $('.editor-panel').removeClass('active');
  
  // Set modal title
  $('#editor-modal-title').text('Editing: ' + projectName);
  $('#editor-file-info').text(compose_root + '/' + project);
  
  // Show loading state
  $('#editor-modal-overlay').addClass('active');
  $('#editor-validation-compose').html('<i class="fa fa-spinner fa-spin editor-validation-icon"></i> Loading files...').removeClass('valid error warning');
  
  // Load all files and settings
  loadEditorFiles(project);
  loadSettingsData(project, projectName);
  
  // Switch to appropriate initial tab (default to 'compose')
  var targetTab = initialTab || 'compose';
  switchTab(targetTab);
}

function loadEditorFiles(project) {
  var loadPromises = [];
  
  // Load compose file
  loadPromises.push(
    $.post(caURL, {action:'getYml', script:project}).then(function(data) {
      if (data) {
        var response = jQuery.parseJSON(data);
        editorModal.originalContent['compose'] = response.content || '';
        editorModal.editors['compose'].setValue(response.content || '', -1);
      }
    }).fail(function() {
      var errorContent = '# Error loading file';
      editorModal.originalContent['compose'] = errorContent;
      editorModal.editors['compose'].setValue(errorContent, -1);
    })
  );
  
  // Load env file
  loadPromises.push(
    $.post(caURL, {action:'getEnv', script:project}).then(function(data) {
      if (data) {
        var response = jQuery.parseJSON(data);
        editorModal.originalContent['env'] = response.content || '';
        editorModal.editors['env'].setValue(response.content || '', -1);
      }
    }).fail(function() {
      var errorContent = '# Error loading file';
      editorModal.originalContent['env'] = errorContent;
      editorModal.editors['env'].setValue(errorContent, -1);
    })
  );
  
  // When all files are loaded
  $.when.apply($, loadPromises).then(function() {
    // Run validation on compose file
    validateYaml('compose', editorModal.editors['compose'].getValue());
  }).fail(function() {
    $('#editor-validation-compose').html('<i class="fa fa-exclamation-triangle editor-validation-icon"></i> Error loading some files').removeClass('valid').addClass('error');
  });
}

// Load settings data into the settings panel
function loadSettingsData(project, projectName) {
  // Set the name from projectName (display name)
  $('#settings-name').val(projectName || '');
  editorModal.originalSettings['name'] = projectName || '';
  
  // Load description
  $.post(caURL, {action:'getDescription', script:project}).then(function(data) {
    if (data) {
      var response = JSON.parse(data);
      var desc = (response.content || '').replace(/<br>/g, '\n');
      $('#settings-description').val(desc);
      editorModal.originalSettings['description'] = desc;
    }
  }).fail(function() {
    $('#settings-description').val('');
    editorModal.originalSettings['description'] = '';
  });
  
  // Load stack settings (icon URL and env path)
  $.post(caURL, {action:'getStackSettings', script:project}).then(function(data) {
    if (data) {
      var response = JSON.parse(data);
      if (response.result === 'success') {
        // Icon URL
        var iconUrl = response.iconUrl || '';
        $('#settings-icon-url').val(iconUrl);
        editorModal.originalSettings['icon-url'] = iconUrl;
        if (iconUrl && (iconUrl.startsWith('http://') || iconUrl.startsWith('https://'))) {
          $('#settings-icon-preview-img').attr('src', iconUrl);
          $('#settings-icon-preview').show();
        } else {
          $('#settings-icon-preview').hide();
        }
        
        // ENV path
        var envPath = response.envPath || '';
        $('#settings-env-path').val(envPath);
        editorModal.originalSettings['env-path'] = envPath;
        
        // Default profile
        var defaultProfile = response.defaultProfile || '';
        $('#settings-default-profile').val(defaultProfile);
        editorModal.originalSettings['default-profile'] = defaultProfile;
        
        // Available profiles (from the profiles file)
        var availableProfiles = response.availableProfiles || [];
        if (availableProfiles.length > 0) {
          $('#settings-profiles-list').text(availableProfiles.join(', '));
          $('#settings-available-profiles').show();
        } else {
          $('#settings-available-profiles').hide();
        }
      }
    }
  }).fail(function() {
    $('#settings-icon-url').val('');
    $('#settings-env-path').val('');
    $('#settings-default-profile').val('');
    editorModal.originalSettings['icon-url'] = '';
    editorModal.originalSettings['env-path'] = '';
    editorModal.originalSettings['default-profile'] = '';
    $('#settings-icon-preview').hide();
    $('#settings-available-profiles').hide();
  });
}

// Load labels data for the WebUI Labels panel
function loadLabelsData() {
  var project = editorModal.currentProject;
  if (!project) return;
  
  $('#labels-services-container').html('<div class="labels-empty-state"><i class="fa fa-spinner fa-spin"></i> Loading services...</div>');
  
  // Load both compose file and override file to build the labels UI
  var composePromise = $.post(caURL, {action:'getYml', script:project});
  var overridePromise = $.post(caURL, {action:'getOverride', script:project});
  
  $.when(composePromise, overridePromise).then(function(composeResult, overrideResult) {
    try {
      var composeData = JSON.parse(composeResult[0]);
      var overrideData = JSON.parse(overrideResult[0]);
      
      if (composeData.result !== 'success') {
        throw new Error('Failed to load compose file');
      }
      
      var mainDoc = jsyaml.load(composeData.content) || {services: {}};
      var overrideDoc = jsyaml.load(overrideData.content || '') || {services: {}};
      
      // Ensure override has services object
      if (!overrideDoc.services) {
        overrideDoc.services = {};
      }
      
      editorModal.labelsData = {
        mainDoc: mainDoc,
        overrideDoc: overrideDoc
      };
      
      renderLabelsUI(mainDoc, overrideDoc);
      
    } catch (e) {
      console.error('Failed to parse compose files for labels:', e);
      $('#labels-services-container').html('<div class="labels-empty-state"><i class="fa fa-exclamation-triangle"></i> Error loading services: ' + escapeHtml(e.message) + '</div>');
    }
  }).fail(function() {
    $('#labels-services-container').html('<div class="labels-empty-state"><i class="fa fa-exclamation-triangle"></i> Failed to load compose files</div>');
  });
}

// Render the WebUI Labels UI
function renderLabelsUI(mainDoc, overrideDoc) {
  var html = '';
  var deletedHtml = '';
  var hasServices = false;
  var hasDeletedServices = false;
  
  // Process services from main compose file
  for (var serviceKey in mainDoc.services) {
    hasServices = true;
    var service = mainDoc.services[serviceKey];
    var overrideService = overrideDoc.services[serviceKey] || {labels: {}};
    
    // Ensure override service has proper structure
    if (!overrideService.labels) {
      overrideDoc.services[serviceKey] = overrideDoc.services[serviceKey] || {};
      overrideDoc.services[serviceKey].labels = overrideDoc.services[serviceKey].labels || {};
      overrideDoc.services[serviceKey].labels[<?php echo json_encode($docker_label_managed); ?>] = <?php echo json_encode($docker_label_managed_name); ?>;
      overrideService = overrideDoc.services[serviceKey];
    }
    
    var containerName = service.container_name || serviceKey;
    var iconValue = findLabelValue(overrideService, service, icon_label);
    var webuiValue = findLabelValue(overrideService, service, webui_label);
    var shellValue = findLabelValue(overrideService, service, shell_label);
    
    // Store original values
    editorModal.originalLabels[serviceKey + '_icon'] = iconValue;
    editorModal.originalLabels[serviceKey + '_webui'] = webuiValue;
    editorModal.originalLabels[serviceKey + '_shell'] = shellValue;
    
    var iconSrc = iconValue || '/plugins/dynamix.docker.manager/images/question.png';
    html += '<div class="labels-service" data-service="' + escapeAttr(serviceKey) + '">';
    html += '<div class="labels-service-header">';
    html += '<img class="labels-service-icon" id="label-icon-preview-' + escapeAttr(serviceKey) + '" src="' + escapeAttr(iconSrc) + '" alt="" onerror="this.src=\'/plugins/dynamix.docker.manager/images/question.png\'">';
    html += '<span class="labels-service-name">' + escapeHtml(containerName) + '</span>';
    html += '</div>';
    html += '<div class="labels-service-fields">';
    html += '<div class="labels-field">';
    html += '<label><i class="fa fa-picture-o"></i> Icon URL</label>';
    html += '<input type="text" id="label-' + escapeAttr(serviceKey) + '-icon" value="' + escapeAttr(iconValue) + '" placeholder="https://example.com/icon.png" data-service="' + escapeAttr(serviceKey) + '" data-field="icon">';
    html += '</div>';
    html += '<div class="labels-field">';
    html += '<label><i class="fa fa-globe"></i> WebUI URL</label>';
    html += '<input type="text" id="label-' + escapeAttr(serviceKey) + '-webui" value="' + escapeAttr(webuiValue) + '" placeholder="http://[IP]:[PORT:8080]/" data-service="' + escapeAttr(serviceKey) + '" data-field="webui">';
    html += '</div>';
    html += '<div class="labels-field">';
    html += '<label><i class="fa fa-terminal"></i> Shell</label>';
    html += '<input type="text" id="label-' + escapeAttr(serviceKey) + '-shell" value="' + escapeAttr(shellValue) + '" placeholder="/bin/bash" data-service="' + escapeAttr(serviceKey) + '" data-field="shell">';
    html += '</div>';
    html += '</div>';
    html += '</div>';
  }
  
  // Check for deleted services in override that aren't in main
  for (var serviceKey in overrideDoc.services) {
    if (!(serviceKey in mainDoc.services)) {
      hasDeletedServices = true;
      var overrideService = overrideDoc.services[serviceKey];
      var containerName = (overrideService && overrideService.container_name) || serviceKey;
      var iconValue = findLabelValue(overrideService, {}, icon_label);
      var webuiValue = findLabelValue(overrideService, {}, webui_label);
      var shellValue = findLabelValue(overrideService, {}, shell_label);
      
      var deletedIconSrc = iconValue || '/plugins/dynamix.docker.manager/images/question.png';
      deletedHtml += '<div class="labels-service deleted" data-service="' + escapeAttr(serviceKey) + '" data-deleted="true">';
      deletedHtml += '<div class="labels-service-header">';
      deletedHtml += '<img class="labels-service-icon" src="' + escapeAttr(deletedIconSrc) + '" alt="" onerror="this.src=\'/plugins/dynamix.docker.manager/images/question.png\'">';
      deletedHtml += '<span class="labels-service-name">' + escapeHtml(containerName) + ' <span style="color:#f44336;font-size:0.8em;">(will be removed)</span></span>';
      deletedHtml += '</div>';
      deletedHtml += '<div class="labels-service-fields">';
      deletedHtml += '<div class="labels-field"><label><i class="fa fa-picture-o"></i> Icon</label><input type="text" value="' + escapeAttr(iconValue) + '" disabled></div>';
      deletedHtml += '<div class="labels-field"><label><i class="fa fa-globe"></i> WebUI</label><input type="text" value="' + escapeAttr(webuiValue) + '" disabled></div>';
      deletedHtml += '<div class="labels-field"><label><i class="fa fa-terminal"></i> Shell</label><input type="text" value="' + escapeAttr(shellValue) + '" disabled></div>';
      deletedHtml += '</div>';
      deletedHtml += '</div>';
    }
  }
  
  if (!hasServices) {
    html = '<div class="labels-empty-state"><i class="fa fa-cubes"></i> No services defined in docker-compose.yml</div>';
  }
  
  if (hasDeletedServices) {
    html += '<div class="labels-deleted-section">';
    html += '<div class="labels-deleted-title" onclick="toggleDeletedServices(this)"><i class="fa fa-chevron-right"></i> Orphaned Services (will be removed on save)</div>';
    html += '<div class="labels-deleted-services">' + deletedHtml + '</div>';
    html += '</div>';
  }
  
  $('#labels-services-container').html(html);
  
  // Attach change handlers to label inputs
  $('#labels-services-container').find('input[data-service]').on('input', function() {
    var service = $(this).data('service');
    var field = $(this).data('field');
    var key = service + '_' + field;
    var currentValue = $(this).val();
    var originalValue = editorModal.originalLabels[key] || '';
    
    if (currentValue !== originalValue) {
      editorModal.modifiedLabels.add(key);
    } else {
      editorModal.modifiedLabels.delete(key);
    }
    
    // Live icon preview with debounce
    if (field === 'icon') {
      var $input = $(this);
      clearTimeout($input.data('iconDebounce'));
      $input.data('iconDebounce', setTimeout(function() {
        var iconUrl = $input.val().trim();
        var $preview = $('#label-icon-preview-' + service);
        if (iconUrl) {
          $preview.attr('src', iconUrl);
        } else {
          $preview.attr('src', '/plugins/dynamix.docker.manager/images/question.png');
        }
      }, 300));
    }
    
    updateSaveButtonState();
    updateTabModifiedState();
  });
}

// Helper to find label value from override or main service
function findLabelValue(overrideService, mainService, labelKey) {
  if (overrideService && overrideService.labels && overrideService.labels[labelKey]) {
    return overrideService.labels[labelKey];
  }
  if (mainService && mainService.labels && mainService.labels[labelKey]) {
    return mainService.labels[labelKey];
  }
  return '';
}

// Toggle deleted services visibility
function toggleDeletedServices(el) {
  var $title = $(el);
  var $services = $title.next('.labels-deleted-services');
  $title.toggleClass('expanded');
  $services.toggleClass('visible');
}

function validateYaml(type, content) {
  if (type === 'env') {
    // Basic validation for env files
    updateValidation(type, content);
    return;
  }
  
  try {
    if (content.trim()) {
      jsyaml.load(content);
    }
    updateValidation(type, content, true);
  } catch (e) {
    updateValidation(type, content, false, e.message);
  }
}

function updateValidation(type, content, isValid, errorMsg) {
  var validationEl = $('#editor-validation-' + type);
  
  // Handle env files separately (no YAML validation needed)
  if (type === 'env') {
    var lines = content.split('\n').filter(l => l.trim() && !l.trim().startsWith('#'));
    validationEl.html('<i class="fa fa-info-circle editor-validation-icon"></i> ' + lines.length + ' environment variable(s)');
    validationEl.removeClass('error warning').addClass('valid');
    return;
  }
  
  // If isValid is undefined, run actual YAML validation
  if (isValid === undefined) {
    validateYaml(type, content);
    return;
  }
  
  if (isValid) {
    validationEl.html('<i class="fa fa-check editor-validation-icon"></i> YAML syntax is valid');
    validationEl.removeClass('error warning').addClass('valid');
  } else {
    // Truncate error message to first line for cleaner display
    var shortError = errorMsg.split('\n')[0].substring(0, 100);
    if (errorMsg.length > 100) shortError += '...';
    // Use text node to prevent XSS from malicious YAML content
    validationEl.empty()
      .append('<i class="fa fa-times editor-validation-icon"></i> YAML Error: ')
      .append(document.createTextNode(shortError));
    validationEl.removeClass('valid warning').addClass('error');
  }
}

function updateSaveButtonState() {
  var totalChanges = editorModal.modifiedTabs.size + editorModal.modifiedSettings.size + editorModal.modifiedLabels.size;
  var hasChanges = totalChanges > 0;
  $('#editor-btn-save-all').prop('disabled', !hasChanges);
  
  if (hasChanges) {
    $('#editor-btn-save-all').text('Save All (' + totalChanges + ')');
  } else {
    $('#editor-btn-save-all').text('Save All');
  }
}

function saveCurrentTab() {
  var currentTab = editorModal.currentTab;
  if (!currentTab) return;
  
  // Only save editor tabs
  if (currentTab !== 'compose' && currentTab !== 'env') return;
  if (!editorModal.modifiedTabs.has(currentTab)) return;
  
  saveTab(currentTab).then(function() {
    // Brief feedback in validation panel
    $('#editor-validation-' + currentTab).html('<i class="fa fa-check editor-validation-icon"></i> Saved!').removeClass('error warning').addClass('valid');
    setTimeout(function() {
      validateYaml(currentTab, editorModal.editors[currentTab].getValue());
    }, 1500);
  }).catch(function() {
    // Error already handled in saveTab's .fail() handler
  });
}

function saveTab(tabName) {
  var content = editorModal.editors[tabName].getValue();
  var project = editorModal.currentProject;
  var actionStr = null;
  
  switch(tabName) {
    case 'compose':
      actionStr = 'saveYml';
      break;
    case 'env':
      actionStr = 'saveEnv';
      break;
    default:
      return Promise.reject('Unknown tab');
  }
  
  return $.post(caURL, {action:actionStr, script:project, scriptContents:content}).then(function(data) {
    if (data) {
      editorModal.originalContent[tabName] = content;
      editorModal.modifiedTabs.delete(tabName);
      $('#editor-tab-' + tabName).removeClass('modified');
      updateSaveButtonState();
      updateTabModifiedState();
      
      // Regenerate override and profiles if compose file was saved
      if (tabName === 'compose') {
        generateOverride(null, project);
        generateProfiles(null, project);
      }
      
      return true;
    }
    return false;
  }).fail(function() {
    swal({
      title: "Save Failed",
      text: "Failed to save " + tabName + " file. Please try again.",
      type: "error"
    });
    return false;
  });
}

// Save all modified changes (files, settings, and labels)
function saveAllChanges() {
  var savePromises = [];
  var totalChanges = editorModal.modifiedTabs.size + editorModal.modifiedSettings.size + editorModal.modifiedLabels.size;
  
  if (totalChanges === 0) {
    return;
  }
  
  // Save modified file tabs
  editorModal.modifiedTabs.forEach(function(tabName) {
    savePromises.push(saveTab(tabName));
  });
  
  // Save settings if modified
  if (editorModal.modifiedSettings.size > 0) {
    savePromises.push(saveSettings());
  }
  
  // Save labels if modified
  if (editorModal.modifiedLabels.size > 0) {
    savePromises.push(saveLabels());
  }
  
  $.when.apply($, savePromises).then(function() {
    var results = Array.prototype.slice.call(arguments);
    var allSucceeded = results.every(function(result) {
      return result === true;
    });
    
    if (allSucceeded) {
      swal({
        title: "Saved!",
        text: "All changes have been saved.",
        type: "success",
        timer: 1500,
        showConfirmButton: false
      });
    } else {
      swal({
        title: "Partial Save",
        text: "Some items could not be saved. Please check the error messages and try again.",
        type: "warning"
      });
    }
  }).fail(function() {
    swal({
      title: "Save Failed",
      text: "An error occurred while saving. Please try again.",
      type: "error"
    });
  });
}

// Save settings
function saveSettings() {
  var project = editorModal.currentProject;
  var savePromises = [];
  var needsReload = false;
  
  // Save name if modified
  if (editorModal.modifiedSettings.has('name')) {
    var newName = $('#settings-name').val();
    savePromises.push(
      $.post(caURL, {action:'changeName', script:project, newName:newName}).then(function() {
        editorModal.originalSettings['name'] = newName;
        editorModal.modifiedSettings.delete('name');
        needsReload = true;
        return true;
      }).fail(function() {
        return false;
      })
    );
  }
  
  // Save description if modified
  if (editorModal.modifiedSettings.has('description')) {
    var newDesc = $('#settings-description').val().replace(/\n/g, '<br>');
    savePromises.push(
      $.post(caURL, {action:'changeDesc', script:project, newDesc:newDesc}).then(function() {
        editorModal.originalSettings['description'] = $('#settings-description').val();
        editorModal.modifiedSettings.delete('description');
        return true;
      }).fail(function() {
        return false;
      })
    );
  }
  
  // Save icon URL, env path, and default profile if any are modified
  if (editorModal.modifiedSettings.has('icon-url') || editorModal.modifiedSettings.has('env-path') || editorModal.modifiedSettings.has('default-profile')) {
    var iconUrl = $('#settings-icon-url').val();
    var envPath = $('#settings-env-path').val();
    var defaultProfile = $('#settings-default-profile').val();
    savePromises.push(
      $.post(caURL, {action:'setStackSettings', script:project, iconUrl:iconUrl, envPath:envPath, defaultProfile:defaultProfile}).then(function(data) {
        if (data) {
          var response = JSON.parse(data);
          if (response.result === 'success') {
            editorModal.originalSettings['icon-url'] = iconUrl;
            editorModal.originalSettings['env-path'] = envPath;
            editorModal.originalSettings['default-profile'] = defaultProfile;
            editorModal.modifiedSettings.delete('icon-url');
            editorModal.modifiedSettings.delete('env-path');
            editorModal.modifiedSettings.delete('default-profile');
            needsReload = true;
            return true;
          }
        }
        return false;
      }).fail(function() {
        return false;
      })
    );
  }
  
  return $.when.apply($, savePromises).then(function() {
    updateTabModifiedState();
    updateSaveButtonState();
    // Schedule a page reload after the swal closes if needed
    if (needsReload) {
      setTimeout(function() {
        location.reload();
      }, 1800);
    }
    return true;
  });
}

// Save labels to override file
function saveLabels() {
  var project = editorModal.currentProject;
  
  if (!editorModal.labelsData) {
    return $.Deferred().reject().promise();
  }
  
  var mainDoc = editorModal.labelsData.mainDoc;
  var overrideDoc = editorModal.labelsData.overrideDoc;
  
  // Update override doc with values from the form
  for (var serviceKey in mainDoc.services) {
    if (!(serviceKey in overrideDoc.services)) {
      overrideDoc.services[serviceKey] = {
        labels: {}
      };
      overrideDoc.services[serviceKey].labels[<?php echo json_encode($docker_label_managed); ?>] = <?php echo json_encode($docker_label_managed_name); ?>;
    }
    
    var iconValue = $('#label-' + serviceKey + '-icon').val() || '';
    var webuiValue = $('#label-' + serviceKey + '-webui').val() || '';
    var shellValue = $('#label-' + serviceKey + '-shell').val() || '';
    
    if (!overrideDoc.services[serviceKey].labels) {
      overrideDoc.services[serviceKey].labels = {};
    }
    
    overrideDoc.services[serviceKey].labels[icon_label] = iconValue;
    overrideDoc.services[serviceKey].labels[webui_label] = webuiValue;
    overrideDoc.services[serviceKey].labels[shell_label] = shellValue;
  }
  
  // Remove services from override that are no longer in main
  for (var serviceKey in overrideDoc.services) {
    if (!(serviceKey in mainDoc.services)) {
      delete overrideDoc.services[serviceKey];
    }
  }
  
  // Convert to YAML and save
  var rawOverride = jsyaml.dump(overrideDoc, {'forceQuotes': true});
  
  return $.post(caURL, {action:'saveOverride', script:project, scriptContents:rawOverride}).then(function(data) {
    if (data) {
      // Update original labels to match current values
      for (var serviceKey in mainDoc.services) {
        editorModal.originalLabels[serviceKey + '_icon'] = $('#label-' + serviceKey + '-icon').val() || '';
        editorModal.originalLabels[serviceKey + '_webui'] = $('#label-' + serviceKey + '-webui').val() || '';
        editorModal.originalLabels[serviceKey + '_shell'] = $('#label-' + serviceKey + '-shell').val() || '';
      }
      editorModal.modifiedLabels.clear();
      updateTabModifiedState();
      updateSaveButtonState();
      return true;
    }
    return false;
  }).fail(function() {
    swal({
      title: "Save Failed",
      text: "Failed to save WebUI labels. Please try again.",
      type: "error"
    });
    return false;
  });
}

// Keep saveAllTabs for backwards compatibility
function saveAllTabs() {
  saveAllChanges();
}

function closeEditorModal() {
  var totalChanges = editorModal.modifiedTabs.size + editorModal.modifiedSettings.size + editorModal.modifiedLabels.size;
  if (totalChanges > 0) {
    swal({
      title: "Unsaved Changes",
      text: "You have unsaved changes. Are you sure you want to close?",
      type: "warning",
      showCancelButton: true,
      confirmButtonText: "Discard Changes",
      cancelButtonText: "Cancel"
    }, function(confirmed) {
      if (confirmed) {
        doCloseEditorModal();
      }
    });
  } else {
    doCloseEditorModal();
  }
}

function doCloseEditorModal() {
  $('#editor-modal-overlay').removeClass('active');
  editorModal.currentProject = null;
  editorModal.currentTab = 'compose';
  editorModal.modifiedTabs = new Set();
  editorModal.modifiedSettings = new Set();
  editorModal.modifiedLabels = new Set();
  editorModal.originalContent = {};
  editorModal.originalSettings = {};
  editorModal.originalLabels = {};
  editorModal.labelsData = null;
  
  // Clear editor content to avoid showing stale content on next open
  ['compose', 'env'].forEach(function(type) {
    if (editorModal.editors[type]) {
      editorModal.editors[type].setValue('', -1);
    }
  });
  
  // Reset settings fields
  $('#settings-name').val('');
  $('#settings-description').val('');
  $('#settings-icon-url').val('');
  $('#settings-env-path').val('');
  $('#settings-default-profile').val('');
  $('#settings-icon-preview').hide();
  $('#settings-available-profiles').hide();
  
  // Clear labels container
  $('#labels-services-container').html('');
  
  // Reset tab states
  $('.editor-tab').removeClass('modified');
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

function deleteStackByProject(project, projectName) {
  var msgHtml = "Are you sure you want to delete <font color='red'><b>"+escapeHtml(projectName)+"</b></font> (<font color='green'>"+escapeHtml(compose_root)+"/"+escapeHtml(project)+"</font>)?"; 
  swal({
    title: "Delete Stack?",
    text: msgHtml,
    html: true,
    type: "warning",
    showCancelButton: true,
    confirmButtonText: "Delete",
    cancelButtonText: "Cancel"
  }, function(confirmed) {
    if (confirmed) {
      $.post(caURL,{action:'deleteStack',stackName:project},function(data) {
        if (data) {
          var response = JSON.parse(data);
          if (response.result == "warning") {
            swal({
              title: "Files remain on disk.",
              text: response.message,
              type: "warning"
            }, function() {
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
  html += '<th>Source</th>';
  html += '<th>Tag</th>';
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
    
    // Parse image - handle docker.io/ prefix and @sha256: digest
    // Format could be: docker.io/library/redis:6.2-alpine@sha256:abc123...
    var imageForParsing = image;
    if (imageForParsing.indexOf('docker.io/') === 0) {
      imageForParsing = imageForParsing.substring(10);
    }
    
    // Check for @sha256: digest suffix
    var digestSuffix = '';
    var digestPos = imageForParsing.indexOf('@sha256:');
    if (digestPos !== -1) {
      digestSuffix = '@' + imageForParsing.substring(digestPos + 1, digestPos + 20); // @sha256:xxxx (first 12 chars of digest)
      imageForParsing = imageForParsing.substring(0, digestPos);
    }
    
    // Now split by : for tag
    var imageParts = imageForParsing.split(':');
    var imageSource = imageParts[0] || ''; // Image name without tag
    var imageTag = (imageParts[1] || 'latest') + digestSuffix; // Include digest suffix if present
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
    var ctIsPinned = container.isPinned || false;
    var ctPinnedDigest = container.pinnedDigest || '';
    
    if (ctIsPinned) {
      // Image is pinned with SHA256 digest - show pinned status
      html += '<span class="cyan-text" style="white-space:nowrap;"><i class="fa fa-thumb-tack fa-fw"></i> pinned</span>';
      if (ctPinnedDigest) {
        html += '<div style="font-family:monospace;font-size:0.85em;color:#17a2b8;margin-top:2px;">' + escapeHtml(ctPinnedDigest) + '</div>';
      }
    } else if (ctHasUpdate) {
      // Update available - orange "update ready" style with SHA diff
      html += '<a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + escapeAttr(project) + '\', \'' + escapeAttr(stackId) + '\');">';
      html += '<span class="orange-text" style="white-space:nowrap;"><i class="fa fa-flash fa-fw"></i> update ready</span>';
      html += '</a>';
      if (ctLocalSha && ctRemoteSha) {
        // Always show SHA diff (not just in advanced view)
        html += '<div style="font-family:monospace;font-size:0.85em;margin-top:2px;">';
        html += '<span style="color:#f80;">' + escapeHtml(ctLocalSha) + '</span>';
        html += ' <i class="fa fa-arrow-right" style="margin:0 4px;color:#3c3;"></i> ';
        html += '<span style="color:#3c3;">' + escapeHtml(ctRemoteSha) + '</span>';
        html += '</div>';
      }
    } else if (ctUpdateStatus === 'up-to-date') {
      // No update - green "up-to-date" style
      html += '<span class="green-text" style="white-space:nowrap;"><i class="fa fa-check fa-fw"></i> up-to-date</span>';
      if (ctLocalSha) {
        // Show SHA in advanced view only for up-to-date containers
        html += '<div class="advanced" style="font-family:monospace;font-size:0.85em;color:#666;">' + escapeHtml(ctLocalSha) + '</div>';
      }
    } else {
      // Unknown/not checked
      html += '<span style="white-space:nowrap;color:#888;"><i class="fa fa-question-circle fa-fw"></i> not checked</span>';
    }
    html += '</td>';
    
    // Source (image name without tag)
    html += '<td><span class="docker_readmore" style="color:#606060;">' + escapeHtml(imageSource) + '</span></td>';
    
    // Tag (image tag)
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
        swal({title: 'Console', text: 'Terminal not available', type: 'info'});
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
      swal({title: 'Logs', text: 'Terminal not available', type: 'info'});
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
        swal({
          title: 'Action Failed',
          text: escapeHtml(response.message) || 'Failed to ' + action + ' container',
          type: 'error'
        });
      }
    }
  }).fail(function() {
    // Restore icon
    if ($icon.is('i')) $icon.removeClass().addClass(originalClass);
    swal({
      title: 'Action Failed',
      text: 'Failed to ' + action + ' container',
      type: 'error'
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
  
  // Edit Stack
  opts.push({text: 'Edit Stack', icon: 'fa-edit', action: function(e) {
    e.preventDefault();
    openEditorModalByProject(project, projectName);
  }});
  
  opts.push({divider: true});
  
  // View Logs
  opts.push({text: 'View Logs', icon: 'fa-navicon', action: function(e) {
    e.preventDefault();
    ComposeLogs(project);
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

// Row click handler - expand/collapse stack details
$(document).on('click', 'tr.compose-sortable[id^="stack-row-"]', function(e) {
  var $target = $(e.target);
  
  // Don't expand if clicking on interactive elements
  if ($target.closest('[data-stackid]').length ||      // Stack icon (context menu)
      $target.closest('.expand-icon').length ||        // Expand arrow
      $target.closest('.compose-updatecolumn a').length ||     // Update links
      $target.closest('.compose-updatecolumn .exec').length || // Update actions
      $target.closest('.auto_start').length ||         // Autostart toggle
      $target.closest('.switchButton').length ||       // Switch button wrapper
      $target.closest('a').length ||                   // Any link
      $target.closest('button').length ||              // Any button
      $target.closest('input').length) {               // Any input
    return;
  }
  
  var stackId = this.id.replace('stack-row-', '');
  if (stackId) {
    toggleStackDetails(stackId);
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
<tr><td colspan='8'></td></tr>
</tbody>
</table>
<span class='tipsterallowed' hidden>
<input type='button' value='Add New Stack' onclick='addStack();'>
<input type='button' value='Start All' onclick='startAllStacks();' id='startAllBtn'>
<input type='button' value='Stop All' onclick='stopAllStacks();' id='stopAllBtn'>
<input type='button' value='Check for Updates' onclick='checkAllUpdates();' id='checkUpdatesBtn'>
<input type='button' value='Update All' onclick='updateAllStacks();' id='updateAllBtn' disabled>
<label style='margin-left:10px;cursor:pointer;vertical-align:middle;' title='When enabled, only stacks with Autostart enabled will be affected'>
  <input type='checkbox' id='autostartOnlyToggle' style='vertical-align:middle;'>
  <span style='vertical-align:middle;'>Autostart only</span>
</label>
<a href='/Settings/compose.manager.settings' style='margin-left:20px;'><input type='button' value='Settings'></a>
</span><br>

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

<!-- Editor Modal -->
<div id="editor-modal-overlay" class="editor-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="editor-modal-title">
  <div class="editor-modal">
    <!-- Modal Header -->
    <div class="editor-modal-header">
      <h2 class="editor-modal-title" id="editor-modal-title">Edit Stack</h2>
      <button class="editor-modal-close" onclick="closeEditorModal()" aria-label="Close editor modal">
        <i class="fa fa-times"></i>
      </button>
    </div>
    
    <!-- Unified Tab Bar -->
    <div class="editor-tabs" role="tablist">
      <button class="editor-tab active" id="editor-tab-compose" onclick="switchTab('compose')" role="tab" aria-selected="true" aria-controls="editor-panel-compose">
        <i class="fa fa-file-code-o" aria-hidden="true"></i>
        docker-compose.yml
        <span class="editor-tab-modified" aria-hidden="true"></span>
      </button>
      <button class="editor-tab" id="editor-tab-env" onclick="switchTab('env')" role="tab" aria-selected="false" aria-controls="editor-panel-env">
        <i class="fa fa-cog" aria-hidden="true"></i>
        .env
        <span class="editor-tab-modified" aria-hidden="true"></span>
      </button>
      <button class="editor-tab" id="editor-tab-labels" onclick="switchTab('labels')" role="tab" aria-selected="false" aria-controls="editor-panel-labels">
        <i class="fa fa-tags" aria-hidden="true"></i>
        WebUI Labels
        <span class="editor-tab-modified" aria-hidden="true"></span>
      </button>
      <button class="editor-tab" id="editor-tab-settings" onclick="switchTab('settings')" role="tab" aria-selected="false" aria-controls="editor-panel-settings">
        <i class="fa fa-sliders" aria-hidden="true"></i>
        Settings
        <span class="editor-tab-modified" aria-hidden="true"></span>
      </button>
    </div>
    
    <!-- ========== COMPOSE EDITOR PANEL ========== -->
    <div class="editor-panel active" id="editor-panel-compose" role="tabpanel" aria-labelledby="editor-tab-compose">
      <div class="editor-modal-body">
        <div class="editor-container active" id="editor-container-compose">
          <div id="editor-compose" style="width: 100%; height: 100%;"></div>
        </div>
      </div>
      <div class="editor-validation" id="editor-validation-compose">
        <i class="fa fa-check editor-validation-icon"></i> Ready
      </div>
    </div>
    
    <!-- ========== ENV EDITOR PANEL ========== -->
    <div class="editor-panel" id="editor-panel-env" role="tabpanel" aria-labelledby="editor-tab-env">
      <div class="editor-modal-body">
        <div class="editor-container active" id="editor-container-env">
          <div id="editor-env" style="width: 100%; height: 100%;"></div>
        </div>
      </div>
      <div class="editor-validation" id="editor-validation-env">
        <i class="fa fa-check editor-validation-icon"></i> Ready
      </div>
    </div>
    
    <!-- ========== WEBUI LABELS PANEL ========== -->
    <div class="editor-panel" id="editor-panel-labels" role="tabpanel" aria-labelledby="editor-tab-labels">
      <div class="labels-panel">
        <div class="labels-panel-header">
          <p>Configure icons, WebUI links, and shell commands for each service. These labels integrate your containers with the unRAID Docker UI.</p>
        </div>
        <div id="labels-services-container">
          <div class="labels-empty-state">
            <i class="fa fa-spinner fa-spin"></i>
            Loading services...
          </div>
        </div>
      </div>
    </div>
    
    <!-- ========== SETTINGS PANEL ========== -->
    <div class="editor-panel" id="editor-panel-settings" role="tabpanel" aria-labelledby="editor-tab-settings">
      <div class="settings-panel">
        <!-- Stack Identity -->
        <div class="settings-section">
          <div class="settings-section-title"><i class="fa fa-info-circle"></i> Stack Identity</div>
          
          <div class="settings-field">
            <label for="settings-name">Stack Name</label>
            <input type="text" id="settings-name" placeholder="Enter stack name">
            <div class="settings-field-help">Display name shown in the UI. Does not affect the project folder name.</div>
          </div>
          
          <div class="settings-field">
            <label for="settings-description">Description</label>
            <textarea id="settings-description" placeholder="Enter description for this stack"></textarea>
            <div class="settings-field-help">Brief description of what this stack does.</div>
          </div>
        </div>
        
        <!-- Appearance -->
        <div class="settings-section">
          <div class="settings-section-title"><i class="fa fa-picture-o"></i> Appearance</div>
          
          <div class="settings-field">
            <label for="settings-icon-url">Icon URL</label>
            <input type="url" id="settings-icon-url" placeholder="https://example.com/icon.png">
            <div class="settings-field-help">URL to a custom icon for this stack. Leave empty to use the default icon.</div>
            <div class="settings-field-icon-preview" id="settings-icon-preview" style="display:none;">
              <span>Preview:</span>
              <img id="settings-icon-preview-img" src="" alt="Icon preview" onerror="this.parentElement.style.display='none';">
            </div>
          </div>
        </div>
        
        <!-- Advanced -->
        <div class="settings-section">
          <div class="settings-section-title"><i class="fa fa-sliders"></i> Advanced</div>
          
          <div class="settings-field">
            <label for="settings-env-path">External ENV File Path</label>
            <input type="text" id="settings-env-path" placeholder="Default (uses .env in project folder)">
            <div class="settings-field-help">Path to an external .env file (e.g., /mnt/user/appdata/myapp/.env). Leave empty to use the default .env file in the project folder.</div>
          </div>
          
          <div class="settings-field">
            <label for="settings-default-profile">Default Profile(s)</label>
            <input type="text" id="settings-default-profile" placeholder="Leave empty for all services">
            <div class="settings-field-help">
              Comma-separated list of profiles to use by default for Autostart and multi-stack operations (e.g., "production,monitoring").
              <br>Leave empty to start all services. Available profiles are auto-detected from your compose file.
            </div>
            <div id="settings-available-profiles" style="margin-top:8px;display:none;">
              <span style="color:#888;font-size:0.9em;">Available profiles: </span>
              <span id="settings-profiles-list" style="font-family:monospace;"></span>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Modal Footer -->
    <div class="editor-modal-footer">
      <div class="editor-footer-left">
        <span class="editor-file-info" id="editor-file-info"></span>
        <span class="editor-shortcuts">
          <kbd>Ctrl+S</kbd> Save &nbsp; <kbd>Esc</kbd> Close
        </span>
      </div>
      <div class="editor-footer-right">
        <button class="editor-btn editor-btn-cancel" onclick="closeEditorModal()">Cancel</button>
        <button class="editor-btn editor-btn-save-all" id="editor-btn-save-all" onclick="saveAllChanges()" disabled>Save All</button>
      </div>
    </div>
  </div>
</div>

<script>
// Initialize editor modal after DOM is fully loaded
$(function() {
  updateModalOffset();
  $(window).on('resize', updateModalOffset);
  initEditorModal();
});
</script>

</BODY>
</HTML>
