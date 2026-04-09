# Upgrading Search API Pantheon

## Upgrading to 8.5.x (Solr 9 Support)

Version 8.5.x adds support for Apache Solr 9. Existing Solr 8 installations continue to work without changes.

### New Installation with Solr 9

If you are installing the module for the first time and want to use Solr 9, update your `pantheon.yml` first:

```yaml
search:
  version: 9
```

Then install the module:

```bash
composer require 'drupal/search_api_pantheon:^8.5'
```

### Upgrading from 8.4.x to 8.5.x with Solr 9

If you are upgrading from an existing Solr 8 installation, upgrade the module first, then switch `pantheon.yml`. This order ensures the correct Solr 9 schema and connector are in place before Pantheon provisions the new Solr 9 server.

> **Note:** Switching from Solr 8 to Solr 9 provisions a new Solr core. Existing indexed data will not carry over. A full reindex is required after the switch (see Step 4).

1. **Update the module:**
   ```bash
   composer require 'drupal/search_api_pantheon:^8.5'
   ```

2. **Update your `pantheon.yml`:**
   ```yaml
   search:
     version: 9
   ```

3. **Post the schema for the new Solr version:**
   ```bash
   drush search-api-pantheon:postSchema
   ```

4. **Reindex content:**
   ```bash
   drush search-api:index
   ```

5. **Clear the cache:**
   ```bash
   drush cr
   ```

6. **Test search functionality** thoroughly on non-production environments before deploying to live. Use `drush search-api-pantheon:diagnose` to verify the configuration.

### Rolling Back to Solr 8

If for some reason you need to revert to Solr 8 after upgrading:

1. Change `pantheon.yml` back to `version: 8`.
2. Re-post the Solr 8 schema:
   ```bash
   drush search-api-pantheon:postSchema
   ```
3. Clear the cache:
   ```bash
   drush cr
   ```
4. Reindex all content:
   ```bash
   drush search-api:index
   ```

The module (8.5.x) supports both Solr 8 and Solr 9, so you do not need to downgrade the module version.

## Upgrading from 8.2.x / 8.3.x to 8.4.x

### Summary of Key Changes in 8.4.x

#### Code Refactoring

- Removed unnecessary overrides for Guzzle, Endpoint, and the Solarium client.

- Pantheon-specific endpoint functionality is moved into the connector, resulting in a 60% reduction in code length.

- The search_api_pantheon_admin submodule is now obsolete. Its sole functionality (posting the schema) is already provided by the `drush search-api-pantheon:postSchema` command.

#### Configuration and Local Development

- Search API Server connector configuration fields are now visible but disabled when running on Pantheon.

- These fields are not disabled on local environments, making local development significantly easier—developers can now simply fill in local connection details. Settings are automatically overridden when deployed to Pantheon.

#### Pantheon Search Server Migration

- Starting in version 8.3.x, the Pantheon Search server id was updated from 'pantheon_solr8' to 'pantheon_search', and the 'Basic Content Index' configuration (previously in config/optional) was replaced with a new 'Primary' index (in config/install).

- Versions 8.3.x and 8.4.x include update hooks that automatically handle server migration when running database updates (drush updb or /update.php). The migration updates the server id, reassigns all indexes to the new server, and flags content for reindexing. You can opt out of this migration if needed (see Step 2 in the [Step-by-Step Upgrade Process](#step-by-step-upgrade-process) below).

#### Drush Commands

- Search API Pantheon Drush commands now automatically use the first server connected via the Pantheon Connector, handling the recent server_id migration.

- Avoid passing server_id in drush diagnostic commands as it is no longer needed or accepted.

### Important Steps Before Upgrading to 8.4.x

1. **Backup your database** - The upgrade can make changes to your database and configuration.
2. **Uninstall search_api_pantheon_admin (if installed)** - This submodule is obsolete as of 8.4.x.
    Before updating the module to 8.4.x, uninstall the search_api_pantheon_admin submodule by running:

   ```bash
   drush pm:uninstall search_api_pantheon_admin   # Uninstall the Admin Sub module
   drush cex                                      # Export configuration
   ```

Its sole functionality (posting the schema) is already provided by the `drush search-api-pantheon:postSchema` command.

### Step-by-Step Upgrade Process

1. **Update via Composer:**
   ```bash
   composer require 'drupal/search_api_pantheon:^8.4'
   ```

   If you encounter errors related to removed hooks or classes, clear the cache:
   ```bash
   drush cr
   ```

2. **(Optional) Skip search server migration:**

   To keep using 'pantheon_solr8' search server add default server to `settings.php` before running database updates:
   ```php
   $settings['default_search_server'] = 'pantheon_solr8';
   ```

3. **Run database updates:**
   ```bash
   drush updb
   # or visit /update.php in your browser
   ```

   **What this does:**

   **Server migration (unless opted out in Step 2):**
   - Updates the search server id from 'pantheon_solr8' to 'pantheon_search'
   - Migrates all indexes previously linked to 'pantheon_solr8' to use the new 'pantheon_search' server
   - Flags existing indexed items for reindexing

4. **Export configuration:**
   ```bash
   drush cex
   ```

5. **Clear cache:**
   ```bash
   drush cr
   ```

6. **Reindex content (Required only if search server was migrated):**

   **Admin UI:**
   - Go to admin/config/search/search-api
   - Select your index -> Index Status tab -> click "Index now"

   **Drush:**
   ```bash
   drush search-api:index [INDEX_NAME]
   ```

7. **Update custom code (if applicable):**

   Update any custom code referencing the old 'pantheon_solr8' server to use 'pantheon_search' instead.

8. **Test search functionality** thoroughly on non-production environments before deploying to live site.

### Upgrade Scenarios

| Source Version | Target Version | Server Migration         | Reindexing Required? | Notes                                                                 |
|----------------|----------------|-------------------------|------------------|----------------------------------------------------------------------|
| 8.2.x          | 8.4.x       | Yes (default)           | Yes           | Updates server Id from `pantheon_solr8` to `pantheon_search`, reassigns all indexes, and flags content for reindexing. |
| 8.2.x/8.3.x    | 8.4.x         | No (opt-out)            | No            | To skip server migration add `$settings['default_search_server'] = 'pantheon_solr8';` before running database updates. |
| 8.3.x          | 8.4.x          | Already `pantheon_search` | No          | No search server migration needed; indexes already use `pantheon_search` search server.       |

### Common Upgrade Issues

| Issue | Solution |
|-------|----------|
| Server pantheon_solr8 not found | Verify migration completed successfully by visiting admin/config/search/search-api and checking log messages |
| Errors in custom modules referencing to old server after server migration | After migration of search server, update all 'pantheon_solr8' references to 'pantheon_search' |
| Module warnings: search_api_pantheon_admin module missing | If you didn't uninstall search_api_pantheon_admin module before upgrading to 8.4.x, the update hook `search_api_pantheon_update_10080()` will automatically remove orphaned entries when you run `drush updb`. |
| Search returns no results | Reindex content: `drush search-api:index [INDEX_NAME]` |
| Configuration export/import errors | Clear cache, run updb again, then export config |
| Errors related to hook or plugin discovery | Clear cache: `drush cr` |

### Rolling Back from 8.4.x to 8.3.4

To roll back from version 8.4.x to 8.3.4, restore your database from a backup taken before upgrading to 8.4.x, then revert the Git commit that upgraded the module. After restoring the database and reverting the code, clear the cache using `drush cr` and verify that your search server and indexes at admin/config/search/search-api are properly configured. If applicable, reindex your content via the UI or by running `drush search-api:index [INDEX_NAME]`, then export your configuration.
