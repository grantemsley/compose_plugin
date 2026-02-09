#!/bin/bash
# Compose Manager - Backup Cron Script
# Called by cron to create scheduled backups.

PLUGIN_ROOT="/usr/local/emhttp/plugins/compose.manager"
LOG_TAG="compose.manager"

logger -t "$LOG_TAG" "[backup] Scheduled backup starting..."

# Execute the backup via the PHP backend
result=$(php -r "
  \$_POST = ['action' => 'createBackup'];
  require_once('${PLUGIN_ROOT}/php/defines.php');
  require_once('${PLUGIN_ROOT}/php/backup_functions.php');
  \$r = createBackup();
  echo json_encode(\$r);
")

# Parse result
status=$(echo "$result" | php -r "echo json_decode(file_get_contents('php://stdin'), true)['result'] ?? 'error';")
message=$(echo "$result" | php -r "echo json_decode(file_get_contents('php://stdin'), true)['message'] ?? 'Unknown error';")

if [ "$status" = "success" ]; then
    logger -t "$LOG_TAG" "[backup] Scheduled backup completed: $message"
else
    logger -t "$LOG_TAG" "[backup] Scheduled backup FAILED: $message"
fi
