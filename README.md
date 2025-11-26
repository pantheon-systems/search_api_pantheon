# Search API Pantheon: Solr 8 & Drupal 10+ Integration

[![Search API Pantheon](https://github.com/pantheon-systems/search_api_pantheon/actions/workflows/ci.yml/badge.svg?branch=8.x)](https://github.com/pantheon-systems/search_api_pantheon/actions/workflows/ci.yml)
[![Actively Maintained](https://img.shields.io/badge/Pantheon-Actively_Maintained-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#actively-maintained-support)

## Important

Starting with version **4.0.0**, this module follows [semantic versioning](https://www.drupal.org/docs/develop/git/git-for-drupal-project-maintainers/release-naming-conventions) (MAJOR.MINOR.PATCH).
Version 4.0.0 is the successor to 8.3.4 and includes all previous features plus new improvements.

### Summary of Key Changes in 4.x


- Removed unnecessary overrides for Guzzle, Endpoint, and the Solarium client.

- Pantheon-specific endpoint functionality is moved into the connector, resulting in a 60% reduction in code length.

#### Configuration and Local Development

- Search API Server connector configuration fields are now visible but disabled when running on Pantheon.

- These fields are not disabled on local environments, making local development significantly easier—developers can now simply fill in local connection details. Settings are automatically overridden when deployed to Pantheon.

#### Drush Commands

- Parameters and behavior for Drush commands were kept consistent.

- The code now searches for the first server using the Pantheon connector to handle recent default server renames.

- Avoid passing server_id in drush [diagnostic commands](https://github.com/pantheon-systems/search_api_pantheon/edit/TEST-FORK-PR-212/README.md#diagnostic-commands](https://github.com/pantheon-systems/search_api_pantheon/edit/TEST-FORK-PR-212/README.md#diagnostic-commands)) as it is no longer needed.

### Pantheon Search Server and Index Migration Enhancements in 8.3.x and 4.0.0

- In version 8.3.x, the Pantheon Search server id was updated to 'pantheon_search',and the 'Basic Content Index'  configuration previously found in the config/optional folder has been replaced with  new 'Primary' index in  config/install folder.

- This release now provides a smoother migration of the  search server id from 'pantheon_solr8' to 'pantheon_search' and  all indexes previously linked to 'pantheon_solr8' will be updated to use the new 'pantheon_search' server.

- If you're using the default content_index from earlier versions of the module, no changes are required. It will continue to work as expected.

#### Optional: Skip Search Server Migration
If you prefer to keep using the old server with  id 'pantheon_solr8'  and skip the migration, add the following line to your settings.php file before running database updates:

$settings['default_search_server'] = 'pantheon_solr8';

This will prevent the migration of 'pantheon_solr8' to 'pantheon_search'.

#### Upgrade Instructions

If you're upgrading from version 8.2.x or 8.3.x to 4.0.0, you must run database updates to complete the migration.
Run 'drush updb' in your terminal or visit /update.php in your browser.
Unless you elect to skip the search server migration (see above), running the database update will:

- Update the search server id from 'pantheon_solr8' to 'pantheon_search'.

- Migrate all existing search indexes from the old 'pantheon_solr8' server to the new 'pantheon_search' server.

NB: If you have references to the old server id 'pantheon_solr8' in the custom code, you will need to update those references manually.

#### Post-Update Steps

- After running the database updates, your search server will be updated, which causes previously indexed items to be flagged(queued) for reindexing.
Please re-index using either the Admin UI or Drush.

Admin UI:
Go to admin/config/search/search-api → select your index → Index Status tab → click "Index now"

Drush:
drush search-api:index <INDEX_NAME>  // Replace  <INDEX_NAME>  with the  machine  name of the  index

- After re-indexing is complete and you've verified your search setup, please export the configuration changes using the command:
'drush cex'

## Requirements

This module is for you if you meet the following requirements:

- Using Drupal 10/11
- PHP 8.1 or later
- Hosting the Drupal site on Pantheon's platform
- Your site uses `composer` to install modules and upgrade Drupal core using one of the following integrations:

  - Pantheon's integrated composer (`build step: true` in your pantheon.yml)
  - A Continuous Integration service like Circle CI or Travis

- Have Dashboard access to the platform (necessary to deploy code changes)
- Have Solr enabled on your Pantheon site

## Intent

This module is meant to simplify the usage of [Search API](https://www.drupal.org/project/search_api) and [Search API Solr](https://www.drupal.org/project/search_api_solr) on [Pantheon](https://pantheon.io)'s Platform.

Search API Solr provides the ability to connect to any Solr server by providing numerous configuration options. This module automatically sets the Solr connection options by extending the plugin from Search API Solr. The module also changes its connection information based on different Pantheon environments and each Pantheon Environment has its own [SOLR CORE](#). Doing so eliminates the need to do extra work setting up Solr servers for each environment.

## What it provides

This module provides [Drupal 10+](https://drupal.org) integration with the [Apache Solr project](https://solr.apache.org/guide/8_11/). Pantheon's current version as of the update of this document is 8.11.4.

## Install

### Stable Release (coming soon)

Once the first stable version (4.0.0) is released, it can be installed via Composer by running the following command in your Drupal root:


```bash
composer require 'drupal/search_api_pantheon:^4.0'
```

### Development Version

To install the latest development version, use:

```bash
composer require 'drupal/search_api_pantheon:4.x-dev@dev'
```

## Setup

### Enable Solr on Pantheon

  - Under "Settings" in your Pantheon site dashboard, enable Solr as an add on.
    This feature is available for sandbox sites as well as paid plans at the
    Professional level and above.

#### Enable Solr 8 in your pantheon.yml file

- Add or update the following in your `pantheon.yml` file:

    ```yaml
    search:
      version: 8
    ```

    As you promote the code, the `pantheon.yml` file will follow the code through environments
    enabling the Solr server. However you will need to create an index for each environment
    and ensure the content is indexed after creation. Indices are specific to the Solr core
    with/for which they were created. Indices cannot be exported or moved once created.

### Usage

#### Enable the modules

- Go to `admin/modules` and enable "Search API Pantheon."
- Doing so will also enable Search API and Search API Solr if they are not already enabled.

#### OPTIONAL: Disable Drupal Core's search module

  - If you are using Search API, then you probably will not be using Drupal Core's Search module.
  - Uninstall it to save some confusion in the further configuration steps: `admin/modules/uninstall`.

#### Verify Installation

- Navigate in the Drupal interface to `CONFIG` => `SEARCH & METADATA` => `SEARCH API`
- Validate that the `PANTHEON SEARCH` server and Primary Index exists and is "enabled".

#### Solr versions and schemas

- The version of Solr on Pantheon is Apache Solr 8.8. When you first create
    your index or alter it significantly, you will need to update the SCHEMA
    on the server.

#### Schema Updates

Schema updates can be performed using:

- **Drush:**
  ```bash
  drush search-api-pantheon:postSchema
  ```

### Core Reloading

#### Automatic Core Reload

 Search API Pantheon automatically reloads the Solr core after schema updates to prevent schema reversions and maintain index integrity.

#### Manual Core Reload

If needed, manually reload the core using:

```bash
drush search-api-pantheon:reload
```

#### Use the server with an index

  The following steps are not Pantheon-specific. This module only alters the the configuration of Search API servers. To use a server, you next need to create an index.

  - Go to `admin/config/search/search-api/add-index`.
  - Name your index and choose a data source. If this is your
    first time using Search API, start by selecting "Content"
    as a data source. That option will index the articles,
    basic pages, and other node types you have configured.
  - Select "Pantheon" as the server.
  - Save the index.
  - For this index to be usable, you will also need to configure fields to be searched.
    Select the "fields" tab and `CHOOSE FIELDS TO BE INCLUDED IN THE INDEX`. You may want
    to index many fields. "Title" is a good field to start with.
  - After adding fields to the configuration, make sure the index is full by clicking
    "Index now" or by running cron.

#### Search the Index

- Create a new view returning using the search index of type 'ALL'. Don't worry right now how it's sorted, we're
    going to change that to 'relevance' once we have some data being returned during the search.
- In the view, `CHOOSE FIELDS TO BE INCLUDED IN THE RESULTS` from the fields you added to your index
    when you created it. In addition to the fields you added to the index, choose 'relevance' to add
    to the results.
- Expose any keywords to the user to change and the view will put a KEYWORDS
- Once your search is returning results, you can now sort by the "relevance" field and Solr will give the documents
    a relevance rating. A higher rating means Solr thinks the item is "more relevant" to your search term.

#### Export your changes

- It is a best practice in Drupal to export your changes to `yml` files.
    Using Terminus while in SFTP mode, you can run `terminus drush [PANTHEON_SITE].[PANTHEON_ENV] -- "config:export -y"`
    to export the configuration changes you have made. Once committed, these changes
    can be deployed out to Test and Live environments.

#### Optional Installs

  Any of the optional `search_api` modules should work without issue with Pantheon Solr, including but not limited to:

  - Search API Attachments
  - Search API Facets
  - Search API Autocomplete
  - Search API Spellcheck
  - Search API Ajax

## Pantheon Environments

Each Pantheon environment (Dev, Test, Live, and Multidevs) has its own Solr server. Indexing and searching in one environment does not impact any other environment.

## Solr Jargon

| Term       | Definition                                                                         |
| ---------- | ---------------------------------------------------------------------------------- |
| Commit     | To make document changes permanent in the index.                                   |
| Core       | An instance of the Solr server suitable for creating zero or more indices.         |
| Collection | Solr Cloud's version of a "CORE". Not currently used at Pantheon.                  |
| Document   | A group of fields and their values. The basic unit of data in a collection.        |
| Facet      | The arrangement of search results into categories based on indexed terms.          |
| Field      | The content to be indexed/searched along with metadata.                            |
| Index      | A group of metadata entries gathered by Solr into a searchable catalog.            |
| Schema     | A series of plain text and XML files that describe the data Solr will be indexing. |

## Troubleshooting

### Schema Reversion Issues

If you experience schema reversion issues:

1. Check that core reloading is functioning after schema updates
2. Monitor the Drupal logs for schema update messages
3. Use `drush search-api-pantheon:diagnose` to verify configuration

### Common Issues

| Issue                       | Solution                                      |
| --------------------------- | --------------------------------------------- |
| Schema reverts unexpectedly | Ensure core reload is happening after updates |
| Search index corruption     | Try reposting schema and reindexing content   |
| Core reload failures        | Check Solr logs and connection status         |

### Diagnostic Commands

Starting from version 4.x, diagnostic commands no longer accept the server argument; instead, the command automatically uses the first configured server with the Pantheon connector, irrespective of the specific Solr server configuration being used (e.g., pantheon_solr8 or pantheon_search).

- `drush search-api-pantheon:diagnose` (`sapd`) The DIAGNOSE command will check the various pieces of the Search API install
  and throw errors on the pieces that are not working. This command will develop further as the module nears general availability.

- `drush search-api-pantheon:select` (`saps`) This command will run the given query against Solr server. It's recommended to use
  `?debug=true` in any Solr page (having the right permissions) to get a good query to pass to this command to debug results.

- `drush search-api-pantheon:force-cleanup` (`sapfc`) This command will delete all of the contents for the
  Solr server (no matter if hash or index_id have changed).

- `drush search-api-pantheon:postSchema [path-to-schema]` (`sapps`) This command will upload schema files to the solr server. It can be used to reset a solr schema to the default Pantheon configuration, upgrade a schema, or to use a custom config set.

The current default schema on Pantheon when a new Solr container is provisioned is the 4.2.1 version of the solr8 jump-start config set provided by the Search API Solr module. To upgrade the default Pantheon Search server to a version 4.3.0+ compatible config set, run the following command after you've upgraded the Search API Solr module to your desired version.

`drush search-api-pantheon:postSchema /code/web/modules/contrib/search_api_solr/jump-start/solr8/config-set/`

Once you have enabled the Search API Pantheon module, when you reload the schema the Pantheon module will use the config-set for the version of the Search API Solr module installed in your codebase. See the [Search API Solr 4.3.0 release notes](https://www.drupal.org/project/search_api_solr/releases/4.3.0) for more information about upgrading to a 4.3.0+ compatible schema.

- `drush search-api-pantheon:test-index-and-query` (`sap-tiq`) This command connects to the search server, indexes a single item, and immediately queries it.

## Feedback and Collaboration

Bug reports, feature requests, and feedback should be posted in [the drupal.org issue queue.](https://www.drupal.org/project/issues/search_api_pantheon?categories=All) For code changes, please submit pull requests against the [GitHub repository](https://github.com/pantheon-systems/search_api_pantheon).
