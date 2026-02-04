<?php
/**
 * Compose Util - AJAX Action Handler for Compose Manager
 * 
 * Handles compose actions like up, down, pull, etc.
 * Functions are defined in compose_util_functions.php for testability.
 */

require_once("/usr/local/emhttp/plugins/compose.manager/php/compose_util_functions.php");

switch ($_POST['action']) {
    case 'composeUp':
        echoComposeCommand('up');
        break;
    case 'composeUpRecreate':
        echoComposeCommand('up', true);
        break;
    case 'composeDown':
        echoComposeCommand('down');
        break;
    case 'composeUpPullBuild':
        echoComposeCommand('update');
        break;
    case 'composePull':
        echoComposeCommand('pull');
        break;
    case 'composeStop':
        echoComposeCommand('stop');
        break;
    case 'composeLogs':
        echoComposeCommand('logs');
        break;
    case 'composeUpMultiple':
        $paths = isset($_POST['paths']) ? json_decode($_POST['paths'], true) : array();
        if (!empty($paths)) {
            echoComposeCommandMultiple('up', $paths);
        }
        break;
    case 'composeDownMultiple':
        $paths = isset($_POST['paths']) ? json_decode($_POST['paths'], true) : array();
        if (!empty($paths)) {
            echoComposeCommandMultiple('down', $paths);
        }
        break;
    case 'composeUpdateMultiple':
        $paths = isset($_POST['paths']) ? json_decode($_POST['paths'], true) : array();
        if (!empty($paths)) {
            echoComposeCommandMultiple('update', $paths);
        }
        break;
}
?>