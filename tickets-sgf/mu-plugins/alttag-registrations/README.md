# Alttag Registrations Plugin

WordPress must-use plugin for Alttag registrations.

## Installation Instructions

This plugin supports both PHP 7.4 and PHP 8.0 environments. Follow the appropriate installation method based on your PHP version.

### Important Note About composer.lock

The `composer.lock` file is intentionally excluded from version control because:
1. Different PHP versions require different package versions
2. Each project should generate its own lock file based on its PHP version
3. This prevents conflicts when using the plugin as a git submodule

### For PHP 7.4 Projects

Simply run:
```bash
composer install
```

### For PHP 8.0 Projects

You have two options:

#### Option 1: Override Platform Config Locally
This will set the PHP version for your local environment:
```bash
composer config platform.php "8.0"
composer update
```

#### Option 2: Temporary Override
Use this if you just want to install dependencies without changing the config:
```bash
composer install --ignore-platform-reqs
```

## Package Versions

The plugin uses the following package versions that are compatible with both PHP 7.4 and PHP 8.0:
- endroid/qr-code: ^4.6
- tecnickcom/tcpdf: ^6.6
- phpoffice/phpspreadsheet: ^1.29

## Development

When using this plugin as a git submodule:
1. The composer.json is configured to work in both PHP 7.4 and PHP 8.0 environments
2. The platform config in composer.json is set to PHP 7.4 by default
3. Each project will generate its own composer.lock file based on its PHP version
4. Never commit the composer.lock file to this plugin's repository 