# Taxonomy Term Migrator for WooCommerce

Private WebCreative Studio WordPress plugin for safely migrating WooCommerce taxonomy terms and product relations between taxonomies.

## Release

Update the version in:

- `taxonomy-term-migrator.php`
- `TTM_VERSION`
- `readme.txt` stable tag and changelog

Then push to `main`. GitHub Actions builds `taxonomy-term-migrator.zip`, publishes GitHub release assets, and deploys:

- `https://web-creative.studio/wcs-plugins-update/taxonomy-term-migrator/taxonomy-term-migrator.zip`
- `https://web-creative.studio/wcs-plugins-update/taxonomy-term-migrator/metadata.json`

Required repository secrets:

- `UPDATE_SERVER_HOST`
- `UPDATE_SERVER_USER`
- `UPDATE_SERVER_PASSWORD`
- `UPDATE_SERVER_PATH`
- `UPDATE_SERVER_PUBLIC_BASE_URL`
