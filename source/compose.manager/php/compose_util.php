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
    case 'clientDebug':
        // Accept lightweight client debug messages and log to syslog for server-side inspection
        $msg = isset($_POST['msg']) ? $_POST['msg'] : '';
        $data = isset($_POST['data']) ? $_POST['data'] : '';
        if ($msg) {
            // Use logger() from compose_util_functions.php
            logger("CLIENT_JS: " . $msg . ( $data ? " DATA: " . $data : "" ));
            echo json_encode(array('status' => 'ok'));
        } else {
            echo json_encode(array('status' => 'missing_msg'));
        }
        break;
    case 'containerConsole':
        // Open a ttyd console for a specific container (docker exec -it <name> <shell>)
        $containerName = $_POST['container'] ?? '';
        $shell = $_POST['shell'] ?? 'sh';
        if ($containerName) {
            // Sanitise container name for use as socket filename
            $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $containerName);
            $socketName = "compose_ct_" . $safeName;

            // Kill any existing ttyd on this socket
            $pid = exec("pgrep -a ttyd | awk '/" . preg_quote($socketName, '/') . "\\.sock/{print \$1}'");
            if ($pid) exec("kill " . intval($pid));
            @unlink("/var/tmp/$socketName.sock");

            // Start ttyd with docker exec
            $cmd = "ttyd -R -o -i " . escapeshellarg("/var/tmp/$socketName.sock")
                 . " docker exec -it " . escapeshellarg($containerName)
                 . " " . escapeshellarg($shell) . " > /dev/null 2>&1 &";
            exec($cmd);

            // Return the show_ttyd page URL with the custom socket
            echo "/plugins/compose.manager/php/show_ttyd.php?socket=" . urlencode($socketName);
        }
        break;
}
?>