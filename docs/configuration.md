# Configuration

Access settings via **Settings → Compose** in the Unraid web UI.

## Settings Reference

| Setting | Default | Description |
|---------|---------|-------------|
| **Output Style** | Terminal | Choose between terminal (ttyd) or basic output for compose operations |
| **Projects Folder** | `/boot/config/plugins/compose.manager/projects` | Location where compose project directories are stored |
| **Autostart Force Recreate** | No | Force recreate containers during autostart |
| **Show in Header Menu** | No | Display Compose Manager as a separate page in the header menu |
| **Patch Web UI** | No | Enable integration patches for the native Docker manager UI |
| **Debug to Log** | No | Enable debug logging to syslog |

## Output Styles

### Terminal (ttyd)

- Full terminal output with colors and real-time updates
- Interactive terminal session
- Best for debugging and watching build progress

### Basic

- Simple text output
- Lower resource usage
- Good for headless or automated operations

## Projects Folder

The default location stores all compose configurations on the USB flash drive, ensuring they persist across reboots.

**Structure:**
```
/boot/config/plugins/compose.manager/projects/
├── stack-name/
│   ├── docker-compose.yml
│   ├── docker-compose.override.yml (optional)
│   ├── .env (optional)
│   ├── profiles (auto-generated)
│   └── default_profile (optional)
└── another-stack/
    └── ...
```

## Web UI Patches

When enabled, Compose Manager patches the native Docker manager to:

- Show compose containers grouped by stack
- Display stack status indicators
- Add compose-specific actions to container context menus

Patches are version-specific and located in `source/compose.manager/patches/`.

## Debug Logging

Enable debug logging to troubleshoot issues. Logs are written to the Unraid syslog.

View logs with:
```bash
tail -f /var/log/syslog | grep compose
```
