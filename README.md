# Search API Pantheon: Solr 8 & Drupal 9/10 Integration

[![Search API Pantheon](https://github.com/pantheon-systems/search_api_pantheon/actions/workflows/ci.yml/badge.svg?branch=8.x)](https://github.com/pantheon-systems/search_api_pantheon/actions/workflows/ci.yml)
[![Limited Availability](https://img.shields.io/badge/Pantheon-Limited_Availability-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#limited-availability)

## Important Notice - Schema Reversion Prevention

Starting with version 8.2, this module includes critical fixes to prevent Solr schema reversions that could cause:

- Search functionality outages
- Loss of indexed content
- Unexpected schema reversions
- Site downtime

Users experiencing these issues should upgrade immediately to version 8.1.x-dev.

## Requirements

This module is for you if you meet the following requirements:

- Using Drupal 9.4/10
- Hosting the Drupal site on Pantheon's platform
- Your site uses `composer` to install modules and upgrade Drupal core using one of the following integrations:

  - Pantheon's integrated composer (`build step: true` in your pantheon.yml)
  - A Continuous Integration service like Circle CI or Travis

- Have Dashboard access to the platform (necessary to deploy code changes)

## Intent

This module is meant to simplify the usage of [Search API](https://www.drupal.org/project/search_api) and [Search API Solr](https://www.drupal.org/project/search_api_solr) on [Pantheon](https://pantheon.io)'s Platform.

Search API Solr provides the ability to connect to any Solr server by providing numerous configuration options. This module automatically sets the Solr connection options by extending the plugin from Search API Solr. The module also changes its connection information based on different Pantheon environments and each Pantheon Environment has its own [SOLR CORE](#). Doing so eliminates the need to do extra work setting up Solr servers for each environment.

## What it provides

This module provides [Drupal 9 and 10](https://drupal.org) integration with the [Apache Solr project](https://solr.apache.org/guide/8_8/). Pantheon's current version as of the update of this document is 8.11.4.

## Composer

Composer is the way you should be managing your drupal module requirements. This module will install its dependencies when you use composer to install.

## Dependencies (installed by Composer):

- [Solarium](http://www.solarium-project.org/). Solarium is a Solr client library for PHP and is not Drupal-specific. First, register Drupal.org as a provider of Composer packages. This command should be run locally from the root directory of your Drupal 8 git repository.
- [Search API](https://www.drupal.org/project/search_api). Search API is Drupal's module for indexing content entities.
- [Search API Solr](https://www.drupal.org/project/search_api_solr). Search API Solr makes search API work with Apache Solr. Composer will manage which version.
- [Guzzle](https://docs.guzzlephp.org/en/stable/). Guzzle version 6 is standard with Drupal Core `9.x | 10.x` (read 9.x OR 10.x).

## Install

### Stable Release

To install this module via composer, run the following command in your Drupal root:

```bash
composer require 'drupal/search_api_pantheon:^8.1'
```

### Development Version

Note that the above will install the latest stable release of this module. To install the latest development version, use:

```bash
composer require 'drupal/search_api_pantheon:8.1.x-dev@dev'
```

## Setup

### Platform Support

See [Drupal.org for complete documentation on Search API](https://www.drupal.org/node/1250878).
To configure the connection with Pantheon, perform the following steps on your Dev environment (or a Multidev):

#### Enable Solr on your Pantheon site
  - Under "Settings" in your Pantheon site dashboard, enable Solr as an add on.
    This feature is available for sandbox sites as well as paid plans at the
    Professional level and above.

#### Enable Solr 8 in your pantheon.yml file

  - Add the bolded portion to your `pantheon.yml` file:

    ```yaml
    php_version: 8.1
    database:
      version: 10.4
    drush_version: 10
    search:
      version: 8
    ```

    As you promote the code, the `pantheon.yml` file will follow the code through environments
    enabling the Solr server. However you will need to create an index for each environment
    and ensure the content is indexed after creation. Indices are specific to the Solr core
    with/for which they were created. Indices cannot be exported or moved once created.

### Core Reloading

#### Automatic Core Reload

Starting with version 8.1.x, Search API Pantheon automatically reloads the Solr core after schema updates to prevent schema reversions and maintain index integrity.

#### Schema Updates

Schema updates can be performed through:

- Admin UI: Navigate to `/admin/config/search/search-api/server/pantheon_solr8/pantheon-admin/schema`
- Drush: Run `drush search-api-pantheon:postSchema`

#### Manual Core Reload

If needed, manually reload the core using:

```bash
drush search-api-pantheon:reload
```

### Usage

#### Enable the modules

  - Go to `admin/modules` and enable "Search API Pantheon."
  - Doing so will also enable Search API and Search API Solr if they are not already enabled.

#### OPTIONAL: Disable Drupal Core's search module

  - If you are using Search API, then you probably will not be using Drupal Core's Search module.
  - Uninstall it to save some confusion in the further configuration steps: `admin/modules/uninstall`.

#### The module should install a SEARCH API server for you

  - Navigate in the Drupal interface to `CONFIG` => `SEARCH & METADATA` => `SEARCH API`
  - Validate that the `PANTHEON SEARCH` server exists and is "enabled".

#### Solr versions and schemas

  - The version of Solr on Pantheon is Apache Solr 8.8. When you first create
    your index or alter it significantly, you will need to update the SCHEMA
    on the server. Do that either with a drush command or in the administration
    for the Solr Server.
  - Navigate to `CONFIGURATION` => `SEARCH AND METADATA` => `SEARCH API`
    => `PANTHEON SEARCH` => `PANTHEON SEARCH ADMIN`
  - Choose the button labeled "Post Solr Schema".
  - The module will post a schema specific to your site.

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

  - Create a new view returning `INDEX PANTHEON SOLR8` of type 'ALL'. Don't worry right now how it's sorted, we're
    going to change that to 'relevance' once we have some data being returned during the search.
  - In the view, `CHOOSE FIELDS TO BE INCLUDED IN THE RESULTS` from the fields you added to your index
    when you created it. In addition to the fields you added to the index, choose 'relevance' to add
    to the results.
  - Expose any keywords to the user to change and the view will put a KEYWORDS
  - Once your search is returning results, you can now sort by the "relevance" field and Solr will give the documents
    a relevance rating. A higher rating means Solr thinks the item is "more relevant" to your search term.

#### Export your changes

  - It is a best practice in Drupal z to export your changes to `yml` files.
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

1. Verify you're using version 8.1.x-dev or later
2. Check that core reloading is functioning after schema updates
3. Monitor the Drupal logs for schema update messages
4. Use `drush search-api-pantheon:diagnose` to verify configuration

### Common Issues

| Issue                       | Solution                                      |
| --------------------------- | --------------------------------------------- |
| Schema reverts unexpectedly | Ensure core reload is happening after updates |
| Search index corruption     | Try reposting schema and reindexing content   |
| Core reload failures        | Check Solr logs and connection status         |

### Diagnostic Commands

- `drush search-api-pantheon:diagnose` (`sapd`) The DIAGNOSE command will check the various pieces of the Search API install
  and throw errors on the pieces that are not working. This command will develop further as the module nears general availability.

- `drush search-api-pantheon:select` (`saps`) This command will run the given query against Solr server. It's recommended to use
  `?debug=true` in any Solr page (having the right permissions) to get a good query to pass to this command to debug results.

- `drush search-api-pantheon:force-cleanup` (`sapfc`) This command will delete all of the contents for the given
  Solr server (no matter if hash or index_id have changed).

- `drush search-api-pantheon:postSchema [solr-server] [path-to-schema]` (`sapps`) This command will upload schema files to the solr server. It can be used to reset a solr schema to the default Pantheon configuration, upgrade a schema, or to use a custom config set.

The current default schema on Pantheon when a new Solr container is provisioned is the 4.2.1 version of the solr8 jump-start config set provided by the Search API Solr module. To upgrade the default Pantheon solr 8 server to a version 4.3.0+ compatible config set, run the following command after you've upgraded the Search API Solr module to your desired version.

`drush search-api-pantheon:postSchema pantheon_solr8 /code/web/modules/contrib/search_api_solr/jump-start/solr8/config-set/`

Once you have enabled the Search API Pantheon module, when you reload the schema the Pantheon module will use the config-set for the version of the Search API Solr module installed in your codebase. See the [Search API Solr 4.3.0 release notes](https://www.drupal.org/project/search_api_solr/releases/4.3.0) for more information about upgrading to a 4.3.0+ compatible schema.

- `drush search-api-pantheon:test-index-and-query` (`sap-tiq`) This command will connect to the solr8 server to index a single item and immediately query it.

## Feedback and Collaboration

Bug reports, feature requests, and feedback should be posted in [the drupal.org issue queue.](https://www.drupal.org/project/issues/search_api_pantheon?categories=All) For code changes, please submit pull requests against the [GitHub repository](https://github.com/pantheon-systems/search_api_pantheon).

