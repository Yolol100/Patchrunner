# Changelog

## 3.1.7

- Hardened Settings API capability handling for the plugin settings group.
- Forced production apply setting to remain off unless the server-side production constant is explicitly enabled.
- Disabled the production checkbox in the UI when the server-side constant is absent or false.
- Added upload-size constant for patch packages.
- Added release documentation.

## 3.1.4

- Tightened required capabilities for plugin file-writing actions.
- Added multisite super-admin guard.
- Added double production lock with `AIPR_ALLOW_PRODUCTION_APPLY`.
- Limited patch library size and item count.
- Fixed safe path validation for missing files in new subdirectories.
