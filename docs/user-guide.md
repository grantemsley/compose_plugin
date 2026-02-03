# User Guide

## Managing Stacks

### Creating a Stack

1. Navigate to **Docker → Compose**
2. Click **Add Stack**
3. Enter a stack name and optional description
4. Edit the `docker-compose.yml` file
5. Click **Compose Up**

### Stack Editor

The editor provides four tabs for managing your stack:

| Tab | Purpose |
|-----|---------|
| **Compose File** | Edit your `docker-compose.yml` with syntax highlighting |
| **Settings** | Configure autostart, profiles, and environment files |
| **Env** | Edit environment variables for your stack |
| **Web UI** | Add Unraid-specific labels for web UI integration |

![Editor - Compose File](../source/compose.manager/images/editor-composeFile.png)

![Editor - Settings](../source/compose.manager/images/editor-settings.png)

![Editor - Env](../source/compose.manager/images/editor-env.png)

![Editor - Web UI Labels](../source/compose.manager/images/editor-webUI.png)

### Stack Operations

Each stack supports the following actions:

| Action | Description |
|--------|-------------|
| **Compose Up** | Start all services in the stack |
| **Compose Down** | Stop and remove all containers |
| **Update Stack** | Pull latest images and recreate containers |
| **Edit Stack** | Open the stack editor |
| **Remove Stack** | Delete the stack configuration |

## Profiles

Docker Compose profiles let you selectively start groups of services.

### Defining Profiles

Add profiles to services in your `docker-compose.yml`:

```yaml
services:
  webapp:
    image: nginx:latest
    # No profile - always starts

  debugger:
    image: busybox
    profiles:
      - debug
    # Only starts with 'debug' profile
```

### Using Profiles

- **Auto-Detection**: Compose Manager detects profiles when you save your compose file
- **Profile Selection**: When starting a stack with profiles, you can choose which profile to activate
- **Default Profiles**: Set default profiles in the Settings tab for autostart

### Default Profiles for Autostart

1. Open the stack editor
2. Go to the **Settings** tab
3. Enter profile names in "Default Profile(s)" (comma-separated)

Example: `production,monitoring`

## Autostart

Enable autostart to have stacks start automatically when the Unraid array starts.

1. Click the autostart toggle on a stack
2. Optionally configure default profiles for autostart
3. Stacks will start in order when the array starts

### Force Recreate

Enable "Autostart Force Recreate" in settings to always recreate containers during autostart.

## Environment Files

Specify custom `.env` file paths per stack in the Settings tab. This is useful when:

- Your env file is in a different location
- You want to share env files between stacks
- You have environment-specific configurations

## Indirect Stacks

Reference compose files stored outside the default projects folder. Useful for:

- Keeping compose files with your application data
- Managing compose files in version control
- Sharing configurations across servers

## Web UI Integration

### Unraid Docker Labels

Add Unraid-specific labels to integrate containers with the native Docker UI:

```yaml
services:
  myapp:
    image: myapp:latest
    labels:
      net.unraid.docker.webui: "http://[IP]:[PORT:8080]/"
      net.unraid.docker.icon: "https://example.com/icon.png"
```

The **Web UI** tab in the editor provides a visual interface for adding these labels.

### Patching the Native UI

Enable "Patch Web UI" in settings to show compose containers in the native Docker manager with stack grouping.
