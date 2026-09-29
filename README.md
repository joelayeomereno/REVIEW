# Plugin Folder Installer

WordPress plugin for installing one or more plugins from local folders through a single, rollback-aware workflow.

## Repository status

Active source repository for **Plugin Folder Installer** (plugin version 6.7.1). The previous repository name, `REVIEW`, is historical and should be renamed only after deployment references have been checked.

## Requirements

- WordPress 5.3 or later
- PHP 8.0 or later
- An administrator account with plugin-installation permissions

## Installation

1. Copy this repository into a WordPress plugin directory.
2. Ensure `wp-folder-deployer.php` remains at the plugin root.
3. Activate **Plugin Folder Installer** in WordPress.
4. Open its administration screen and follow the folder deployment workflow.

The plugin validates folder structures, creates temporary ZIP packages when required, keeps rollback backups and activates installed plugins.

## Security

Installation and filesystem operations require authenticated WordPress capabilities and nonces. Test updates in staging before using them on a production site.

## License

GPL-2.0-or-later, as declared in the plugin header.
