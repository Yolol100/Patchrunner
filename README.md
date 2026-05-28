# AI Patch Runner

AI Patch Runner is an admin-only WordPress plugin for reviewing, previewing, applying and rolling back structured JSON patch packages for installed plugins.

## Production safety

Patch applying is blocked on production by default. To allow production applies, both conditions must be true:

1. A trusted server-side configuration explicitly defines `AIPR_ALLOW_PRODUCTION_APPLY` as `true`.
2. The production setting is enabled in the plugin UI.

Keep this disabled for normal production use. Use staging first, review every preview, and keep a rollback path.

## Requirements

- WordPress 6.4 or newer
- PHP 8.0 or newer
- A user role with plugin update privileges
- On multisite: super-admin access

## Development dependencies

Composer is not required for runtime use. A `composer.json` can be added in a separate development repository for PHPCS/WPCS checks, but production zips should stay dependency-free unless external PHP packages are genuinely used.
