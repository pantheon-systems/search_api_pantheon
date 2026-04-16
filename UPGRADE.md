# Upgrade Guide

## Upgrading to 8.5.x

> **Beta Release:** Version 8.5.x is currently in beta. Test thoroughly on non-production environments and report issues in [the drupal.org issue queue](https://www.drupal.org/project/issues/search_api_pantheon?categories=All).

Version 8.5.x adds support for Apache Solr 9. Existing Solr 8 installations continue to work without changes.

### Upgrading from 8.4.x to 8.5.x

If you are upgrading from 8.4.x and want to continue using Solr 8, no migration is needed:

```bash
composer require 'drupal/search_api_pantheon:^8.5@beta'
terminus drush <site>.<env> -- cr
```

### Upgrading from Solr 8 to Solr 9

If you are upgrading from an existing Solr 8 installation, upgrade the module first, then switch `pantheon.yml`. This order ensures the correct Solr 9 schema and connector are in place before Pantheon provisions the new Solr 9 server.

> **Note:** Switching from Solr 8 to Solr 9 provisions a new Solr core. Existing indexed data will not carry over. A full reindex is required after the switch.

1. **Update the module:**
   ```bash
   composer require 'drupal/search_api_pantheon:^8.5@beta'
   ```

2. **Update your `pantheon.yml`:**
   ```yaml
   search:
     version: 9
   ```

3. **Post the schema for the new Solr version:**
   ```bash
   terminus drush <site>.<env> -- search-api-pantheon:postSchema
   ```

4. **Clear the cache:**
   ```bash
   terminus drush <site>.<env> -- cr
   ```

5. **Reindex content:**
   ```bash
   terminus drush <site>.<env> -- search-api:index
   ```

6. **Test search functionality** thoroughly on non-production environments before deploying to live. Use `terminus drush <site>.<env> -- search-api-pantheon:diagnose` to verify the configuration.

### Rolling Back to Solr 8

If for some reason you need to revert to Solr 8 after upgrading:

1. Change `pantheon.yml` back to `version: 8`.
2. Re-post the Solr 8 schema:
   ```bash
   terminus drush <site>.<env> -- search-api-pantheon:postSchema
   ```
3. Clear the cache:
   ```bash
   terminus drush <site>.<env> -- cr
   ```
4. Reindex all content:
   ```bash
   terminus drush <site>.<env> -- search-api:index
   ```

The module (8.5.x) supports both Solr 8 and Solr 9, so you do not need to downgrade the module version.
