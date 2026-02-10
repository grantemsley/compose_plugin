# Compose Manager

A plugin for [unRAID](https://unraid.net/) that installs Docker Compose and adds a management interface to the web UI.

## Screenshots

### Main Compose Manager Interface
![Compose Manager UI](docs/images/compose.png)

### Dashboard Integration
![Dashboard Stacks](docs/images/dashboard.png)

### Stack Editor
The built-in editor provides multiple tabs for managing your compose stack:

| Compose File | Settings |
|:------------:|:--------:|
| ![Editor - Compose File](docs/images/editor-composeFile.png) | ![Editor - Settings](docs/images/editor-settings.png) |

| Env | Web UI Labels |
|:---:|:-------------:|
| ![Editor - Env](docs/images/editor-env.png) | ![Editor - Web UI](docs/images/editor-webUI.png) |

## Features

- **Docker Compose Integration** - Installs the Docker Compose CLI plugin on your unRAID server
- **Web UI Management** - Provides a user-friendly interface to manage your compose stacks directly from the unRAID dashboard
- **Stack Operations** - Start, stop, restart, update, and remove compose stacks with a single click
- **Autostart Support** - Configure stacks to automatically start when the array starts, with optional force recreate
- **Container Status** - View real-time status of containers within each stack (running, stopped, paused, restarting)
- **Environment Files** - Support for custom `.env` file paths per stack
- **Profiles Support** - Full support for Docker Compose profiles with auto-detection and default profiles
- **Editor Integration** - Built-in editor for docker-compose.yml files with syntax highlighting
- **Override Files** - Support for docker-compose.override.yml files
- **Indirect Stacks** - Reference compose files stored in alternate locations
- **Web Terminal** - Integrated terminal output via ttyd for compose operations
- **unRAID Web UI Integration** - Optional patches to integrate compose containers with the native Docker UI

## Installation

Install via the Community Applications plugin in unRAID, or manually install by navigating to:

**Plugins → Install Plugin** and entering the plugin URL:
```
https://raw.githubusercontent.com/mstrhakr/compose_plugin/main/compose.manager.plg
```

## Requirements

- unRAID 6.9.0 or later
- Docker service enabled

## Configuration

Settings can be accessed via **Settings → Compose** in the unRAID web UI. The settings page has three tabs: **Settings**, **Backup/Restore**, and **Log**.

### General Settings

| Setting | Default | Description |
|---------|---------|-------------|
| **Compose Project Directory** | `/boot/config/plugins/compose.manager/projects` | Location where compose project directories are stored |
| **Rich Terminal Output** | Yes | Choose between terminal (ttyd) or basic output for compose operations |
| **Recreate During Autostart** | No | Use `--force-recreate` when autostarting stacks |
| **Wait for Docker Autostart** | No | Wait for Docker's autostart containers to finish before starting compose stacks |
| **Docker Wait Timeout** | 120 seconds | Maximum time to wait for Docker autostart containers to stabilize |
| **Stack Startup Timeout** | 300 seconds | Maximum time to wait for each stack to start during autostart |
| **Show Compose in Header Menu** | No | Add a Compose tab to the main Unraid header navigation bar |
| **Show Compose Stacks Above Docker** | No | Move the Compose Stacks section above Docker Containers (non-tabbed mode) |
| **Hide Compose Containers from Docker** | No | Hide compose-managed containers from the Docker containers table |
| **Show Dashboard Tile** | Yes | Display a Compose Stacks tile on the Dashboard |
| **Hide Compose Containers from Docker Tile on Dashboard** | No | Hide compose containers from the Dashboard Docker tile |
| **Auto Check for Updates** | No | Automatically check for container image updates on page load |
| **Auto Check Interval (days)** | 1 | How often to recheck for updates (0.04 = hourly, 7 = weekly) |
| **Debug Logging** | No | Log detailed compose information to syslog |
| **Patch Docker Page** | No | Patch the Docker page for better compose display (Unraid 6.11 and earlier only) |

### Backup / Restore Settings

| Setting | Default | Description |
|---------|---------|-------------|
| **Backup Destination** | `/boot/config/plugins/compose.manager/backups` | Path where backup archives are stored |
| **Backups to Keep** | 5 | Number of backup archives to retain (0 = unlimited) |
| **Scheduled Backup** | No | Enable automatic scheduled backups via cron |
| **Frequency** | Daily | Daily or weekly backup schedule |
| **Day** | Monday | Day of week for weekly backups |
| **Time** | 03:00 | Time of day for scheduled backups |

## Usage

### Creating a Stack

1. Navigate to **Docker → Compose** (or **Docker Compose** if header menu option is enabled)
2. Click **Add Stack**
3. Enter a name and optionally a description
4. Edit the docker-compose.yml file using the built-in editor
5. Click **Compose Up** to start the stack

### Managing Stacks

Each stack provides the following actions:
- **Compose Up** - Start the stack (with optional profile selection)
- **Compose Down** - Stop and remove containers
- **Update Stack** - Pull latest images and recreate containers
- **Edit Stack** - Modify the docker-compose.yml file
- **Remove Stack** - Delete the stack configuration

### Autostart

Enable autostart for a stack by clicking the autostart toggle. Stacks will automatically start when the unRAID array starts.

## Documentation

For detailed guides, see the [docs](docs/) folder:
- [Getting Started](docs/getting-started.md)
- [User Guide](docs/user-guide.md)
- [Configuration](docs/configuration.md)
- [Profiles](docs/profiles.md)

## Support

- [GitHub Issues](https://github.com/mstrhakr/compose_plugin/issues)
- [unRAID Forums](https://forums.unraid.net/)

## License

This project is open source. See the repository for license details.

## Credits

Originally created by **dcflachs**. This fork maintained by **mstrhakr**.
