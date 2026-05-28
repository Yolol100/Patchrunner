=== AI Patch Runner ===
Contributors: openai
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 3.1.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import reviewed patch packages, preview exact plugin file changes, inspect plugins, manage backups and apply patches safely inside WordPress.

== Production safety ==

Patch applying is double-locked on production environments. To allow production applies, set `AIPR_ALLOW_PRODUCTION_APPLY` to `true` in a trusted server-side configuration file and enable the production setting in the plugin UI. Keep this disabled on normal production sites unless a reviewed rollback plan exists.

== Changelog ==

= 3.1.7 =
* Packaged under the ai-patch-runner plugin slug so the text domain matches the distributable folder.
* Removed manual translation loading for WordPress.org-style automatic translation loading.
* Added PHPCS documentation for read-only admin query parameters and upload temp-path validation.
* Prefixed uninstall variables for WordPress Coding Standards compatibility.

= 3.1.5 =
* Hardened Settings API capability handling for the plugin settings group.
* Forced the production apply setting to remain off unless the server-side production constant is explicitly true.
* Disabled the production checkbox when server-side production apply is not enabled.
* Added release documentation files.

= 3.1.4 =
* Tightened file-writing capability checks from general options management to plugin-update privileges.
* Added multisite super-admin restriction for patch management screens and actions.
* Added a production constant gate in addition to the existing UI setting.
* Limited patch-library storage by item count and serialized size.
* Fixed safe-path validation for new files inside newly created subdirectories.

