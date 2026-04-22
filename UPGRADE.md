# Upgrade Guide

## Upgrading to 8.5.x

> **Beta Release:** Version 8.5.x is currently in beta. Test thoroughly on non-production environments and report issues in [the drupal.org issue queue](https://www.drupal.org/project/issues/search_api_pantheon?categories=All).

Version 8.5.x adds support for Apache Solr 9. Existing Solr 8 installations continue to work without changes.

### Upgrading from 8.4.x to 8.5.x

Version 8.5.x requires [Search API Solr](https://www.drupal.org/project/search_api_solr) 4.3.x.

1. **Update the module** and commit and deploy the changes to your Pantheon environment:

   ```bash
   composer require 'drupal/search_api_pantheon:^8.5@beta'
   git add composer.json composer.lock
   git commit -m "Update search_api_pantheon to 8.5.x"
   git push
   ```

2. **Clear the cache:**

   ```bash
   terminus drush <site>.<env> -- cr
   ```

If you were previously running Search API Solr 4.2.x or earlier, you must also resolve a schema incompatibility. See [Schema incompatibility with Search API Solr 4.3.x](#schema-incompatibility-with-search-api-solr-43x) below.

### Upgrading from Solr 8 to Solr 9

> [!IMPORTANT]
> Switching to Solr 9 provisions a new, empty core. A full reindex is required — search will be unavailable or return incomplete results until reindexing completes.

If you are upgrading from an existing Solr 8 installation, upgrade the module first, then switch `pantheon.yml`.

1. If you are not on Search API Pantheon 8.5.x, update the module to 8.5.x and commit and deploy the changes to your Pantheon environment:

   ```bash
   composer require 'drupal/search_api_pantheon:^8.5@beta'
   git add composer.json composer.lock
   git commit -m "Update search_api_pantheon to 8.5.x"
   git push
   ```

2. **Update your `pantheon.yml`** and commit and deploy the changes to your Pantheon environment:

   ```yaml
   search:
     version: 9
   ```

   ```bash
   git add pantheon.yml
   git commit -m "Switch to Solr 9"
   git push
   ```

3. **Wait a few minutes** for the platform to provision the new Solr 9 core. Verify the core is ready and confirm the Solr version is 9 by running the diagnose command or checking the server status page (`admin/config/search/search-api` > Pantheon Search):

   ```bash
   terminus drush <site>.<env> -- search-api-pantheon:diagnose
   ```

4. **Clear the cache:**

   ```bash
   terminus drush <site>.<env> -- cr
   ```

5. **Post the schema for the new Solr version:**

   ```bash
   terminus drush <site>.<env> -- search-api-pantheon:postSchema
   ```

6. **Clear the index and reindex content:**

   ```bash
   terminus drush <site>.<env> -- search-api:clear
   terminus drush <site>.<env> -- search-api:index
   ```

   Run `search-api:clear` before `search-api:index`. The new Solr core is empty, but Drupal still has your content marked as indexed from the old core. Without clearing first, `search-api:index` assumes everything is already indexed and skips it.

7. **Verify the Solr configuration:**

   ```bash
   terminus drush <site>.<env> -- search-api-pantheon:diagnose
   ```

8. **Test search functionality** thoroughly on non-production environments.

### Rolling Back to Solr 8

- If for some reason you need to revert to Solr 8 after upgrading, the search_api_pantheon 8.5.x module supports both Solr 8 and Solr 9, so you do not need to downgrade the module version. However, be aware that rolling back to Solr 8 from Solr 9 also requires reposting the schema and reindexing the content again.

1. Change `pantheon.yml` back to `version: 8`:

   ```yaml
   search:
     version: 8
   ```

2. Commit and deploy the change to your Pantheon environment:

   ```bash
   git add pantheon.yml
   git commit -m "Revert to Solr 8"
   git push
   ```

3. Clear the cache:

   ```bash
   terminus drush <site>.<env> -- cr
   ```

4. Re-post the Solr 8 schema:

   ```bash
   terminus drush <site>.<env> -- search-api-pantheon:postSchema
   ```

5. Clear the index and reindex content:

   ```bash
   terminus drush <site>.<env> -- search-api:clear
   terminus drush <site>.<env> -- search-api:index
   ```

### Known Issues

#### Schema incompatibility with Search API Solr 4.3.x

If you are upgrading to Solr 9, you can skip the below section. The Solr 9 upgrade process already requires a full reindex, which resolves this incompatibility.

Search API Solr 4.3.x introduced fundamental schema changes (StandardTokenizer, `storeOffsetsWithPositions`) that are incompatible with indexes created by 4.2.x or earlier. After upgrading, if you encounter the following error:

```text
cannot change field "xyz" from index options=DOCS_AND_FREQS_AND_POSITIONS
to inconsistent index options=DOCS_AND_FREQS_AND_POSITIONS_AND_OFFSETS
```

> [!NOTE]
> Resolving this requires clearing all indexed data and performing a full reindex. Plan for temporary search downtime.

Follow these steps to resolve on Pantheon:

1. **Post the updated schema** to upload the 4.3.x config-set to your Solr server:

   ```bash
   terminus drush <site>.<env> -- search-api-pantheon:postSchema
   ```

2. **Disable and re-enable the Solr server** to clear all tracked index data.

   ```bash
   terminus drush <site>.<env> -- search-api:server-disable <server_id>
   terminus drush <site>.<env> -- search-api:server-enable <server_id>
   ```

   You can find your `<server_id>` with `drush search-api:server-list`.

3. **Reload the Solr core:**

   ```bash
   terminus drush <site>.<env> -- search-api-solr:reload <server_id>
   ```

4. **Wait at least 5 minutes**, then verify the schema version has updated using `terminus drush <site>.<env> -- search-api-pantheon:diagnose`. The platform checks for new schemas and issues a core reload within 1–5 minutes.

5. **Re-enable all indexes** (they are automatically disabled when the server is disabled):

   ```bash
   terminus drush <site>.<env> -- search-api:enable
   ```

6. **Reindex all content:**

   ```bash
   terminus drush <site>.<env> -- search-api:index
   ```

7. **Verify** search functionality is working as expected.

For general schema management, see [Schema Updates](README.md#schema-updates) in the README.
