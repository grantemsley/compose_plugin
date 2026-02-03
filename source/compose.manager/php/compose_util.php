<?php

require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");
require_once("/usr/local/emhttp/plugins/dynamix/include/Wrappers.php");

function logger($string) {
	$string = escapeshellarg($string);
	exec("logger ".$string);
}

function execComposeCommandInTTY($cmd, $debug)
{
	global $socket_name;;
	$pid = exec("pgrep -a ttyd|awk '/\\/$socket_name\\.sock/{print \$1}'");
	if ( $debug ) {
		logger($pid);
	}
	if ($pid) exec("kill $pid");
	@unlink("/var/tmp/$socket_name.sock");
	$command = "ttyd -R -o -i '/var/tmp/$socket_name.sock' $cmd". " > /dev/null &"; 
	exec($command);
	if ( $debug ) {
		logger($command);
	}
}

function echoComposeCommand($action)
{
	global $plugin_root;
	global $sName;
	$cfg = parse_plugin_cfg($sName);
	$debug = $cfg['DEBUG_TO_LOG'] == "true";
	$path = isset($_POST['path']) ? urldecode(($_POST['path'])) : "";
	$profile = isset($_POST['profile']) ? urldecode(($_POST['profile'])) : "";
	$unRaidVars = parse_ini_file("/var/local/emhttp/var.ini");
	if ($unRaidVars['mdState'] != "STARTED" ) {
		echo $plugin_root."/scripts/arrayNotStarted.sh";
		if ( $debug ) {
			logger("Array not Started!");
		}
	}
	else
	{
		$composeCommand = array($plugin_root."scripts/compose.sh");

		$projectName = basename($path);
		if ( is_file("$path/name") ) {
			$projectName = trim(file_get_contents("$path/name"));
		}
		$projectName = sanitizeStr($projectName);

		$projectName = "-p$projectName";
		$action = "-c$action";
		$composeCommand[] = $action;
		$composeCommand[] = $projectName;

		$composeFile = "";
		if( isIndirect($path) ) {
			$composeFile = getPath($path);
			$composeFile = "-d$composeFile";
		} 
		else {
			$composeFile .= "$path/docker-compose.yml";
			$composeFile = "-f$composeFile";
		}
		$composeCommand[] = $composeFile;

		if ( is_file("$path/docker-compose.override.yml") ) {
			$composeOverride = "-f$path/docker-compose.override.yml";
			$composeCommand[] = $composeOverride;
		}

		if ( is_file("$path/envpath") ) {
			$envPath = "-e" . trim(file_get_contents("$path/envpath"));
			$composeCommand[] = $envPath;
		}

		// Support multiple profiles (comma-separated)
		if( $profile ) {
			$profileList = array_map('trim', explode(',', $profile));
			foreach ($profileList as $p) {
				if ($p) {
					$composeCommand[] = "-g $p";
				}
			}
		}

		// Pass stack path for timestamp saving
		$composeCommand[] = "-s$path";

		if( $debug ) {
			$composeCommand[] = "--debug";
		}

		if ($cfg['OUTPUTSTYLE'] == "ttyd") {
			$composeCommand = array_map(function($item) {
				return escapeshellarg($item);
			}, $composeCommand);
			$composeCommand = join(" ", $composeCommand);
			execComposeCommandInTTY($composeCommand, $debug);
			if ( $debug ) {
				logger($composeCommand);
			}
			$composeCommand = "/plugins/compose.manager/php/show_ttyd.php";
		}
		else {
			$i = 0;
			$composeCommand = array_reduce($composeCommand, function($v1, $v2) use (&$i) {
				if ($v2[0] == "-") {
					$i++; // increment $i
					return $v1."&arg".$i."=".$v2;
				}
				else{
					return $v1.$v2;
				}
			}, "");
		}
		
		echo $composeCommand;
		if ( $debug ) {
			logger($composeCommand);
		}
	}	
}

// Build command for multiple stacks
function echoComposeCommandMultiple($action, $paths)
{
	global $plugin_root;
	global $sName;
	$cfg = parse_plugin_cfg($sName);
	$debug = $cfg['DEBUG_TO_LOG'] == "true";
	$unRaidVars = parse_ini_file("/var/local/emhttp/var.ini");
	
	if ($unRaidVars['mdState'] != "STARTED" ) {
		echo $plugin_root."/scripts/arrayNotStarted.sh";
		if ( $debug ) {
			logger("Array not Started!");
		}
		return;
	}
	
	// Build a combined command that runs compose up/down for each stack sequentially
	$commands = array();
	$stackNames = array();
	
	foreach ($paths as $path) {
		$composeCommand = array($plugin_root."scripts/compose.sh");

		$projectName = basename($path);
		if ( is_file("$path/name") ) {
			$projectName = trim(file_get_contents("$path/name"));
		}
		$stackNames[] = $projectName;
		$projectName = sanitizeStr($projectName);

		$projectName = "-p$projectName";
		$actionArg = "-c$action";
		$composeCommand[] = $actionArg;
		$composeCommand[] = $projectName;

		$composeFile = "";
		if( isIndirect($path) ) {
			$composeFile = getPath($path);
			$composeFile = "-d$composeFile";
		} 
		else {
			$composeFile .= "$path/docker-compose.yml";
			$composeFile = "-f$composeFile";
		}
		$composeCommand[] = $composeFile;

		if ( is_file("$path/docker-compose.override.yml") ) {
			$composeOverride = "-f$path/docker-compose.override.yml";
			$composeCommand[] = $composeOverride;
		}

		if ( is_file("$path/envpath") ) {
			$envPath = "-e" . trim(file_get_contents("$path/envpath"));
			$composeCommand[] = $envPath;
		}

		// Add default profiles for multi-stack operations
		if ( is_file("$path/default_profile") ) {
			$defaultProfiles = trim(file_get_contents("$path/default_profile"));
			if ($defaultProfiles) {
				// Support comma-separated profiles
				$profileList = array_map('trim', explode(',', $defaultProfiles));
				foreach ($profileList as $p) {
					if ($p) {
						$composeCommand[] = "-g $p";
					}
				}
			}
		}

		// Pass stack path for timestamp saving
		$composeCommand[] = "-s$path";

		if( $debug ) {
			$composeCommand[] = "--debug";
		}

		$commands[] = $composeCommand;
	}
	
	if ($cfg['OUTPUTSTYLE'] == "ttyd") {
		// Build a bash script that runs all commands sequentially
		$bashScript = "bash -c '";
		$first = true;
		foreach ($commands as $idx => $cmd) {
			$cmdStr = implode(" ", array_map('escapeshellarg', $cmd));
			if (!$first) $bashScript .= " && ";
			$bashScript .= "echo \"\\n\\n=== Starting: " . addslashes($stackNames[$idx]) . " ===\\n\" && " . $cmdStr;
			$first = false;
		}
		$bashScript .= "'";
		
		execComposeCommandInTTY($bashScript, $debug);
		if ( $debug ) {
			logger("Multi-stack command: " . $bashScript);
		}
		echo "/plugins/compose.manager/php/show_ttyd.php";
	}
	else {
		// For nchan/traditional output, create a temporary bash script that runs all commands
		$tmpScript = "/tmp/compose_multi_" . uniqid() . ".sh";
		$scriptContent = "#!/bin/bash\n";
		$scriptContent .= "# Multi-stack compose script - auto-generated\n\n";
		
		foreach ($commands as $idx => $cmd) {
			$cmdStr = implode(" ", array_map('escapeshellarg', $cmd));
			$scriptContent .= "echo \"\"\n";
			$scriptContent .= "echo \"========================================\"\n";
			$scriptContent .= "echo \"=== " . str_replace('"', '\\"', $stackNames[$idx]) . " ===\"\n";
			$scriptContent .= "echo \"========================================\"\n";
			$scriptContent .= "echo \"\"\n";
			$scriptContent .= "$cmdStr\n";
			$scriptContent .= "echo \"\"\n";
		}
		
		// Add cleanup at the end
		$scriptContent .= "\necho \"\"\n";
		$scriptContent .= "echo \"========================================\"\n";
		$scriptContent .= "echo \"=== All operations complete ===\"\n";
		$scriptContent .= "echo \"========================================\"\n";
		$scriptContent .= "rm -f " . escapeshellarg($tmpScript) . "\n";
		
		file_put_contents($tmpScript, $scriptContent);
		chmod($tmpScript, 0755);
		
		if ( $debug ) {
			logger("Multi-stack nchan script created: $tmpScript");
		}
		
		echo $tmpScript;
	}
}

switch ($_POST['action']) {
	case 'composeUp':
		echoComposeCommand('up');
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