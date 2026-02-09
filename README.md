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

Settings can be accessed via **Settings → Compose** in the unRAID web UI:

| Setting | Description |
|---------|-------------|
| **Output Style** | Choose between terminal (ttyd) or basic output |
| **Projects Folder** | Location where compose project directories are stored (default: `/boot/config/plugins/compose.manager/projects`) |
| **Autostart Force Recreate** | Force recreate containers during autostart |
| **Show in Header Menu** | Display Compose Manager as a separate page in the header menu under Docker Compose |
| **Patch Web UI** | Enable integration patches for the native Docker manager UI |
| **Debug to Log** | Enable debug logging |

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