# Security policy

AI Patch Runner can write plugin files after an explicit admin review step. Treat it as a privileged maintenance tool.

Recommended use:

- Run on staging first.
- Keep production apply disabled by default.
- Allow production applies only temporarily with `AIPR_ALLOW_PRODUCTION_APPLY` in trusted server-side configuration.
- Restrict access to users who are allowed to update plugins.
- On multisite, restrict use to super-admins.
- Review all diffs before applying a patch.
- Confirm backups and rollback before touching production.
