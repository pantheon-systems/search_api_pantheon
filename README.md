# Search API Pantheon: Solr 8/9 & Drupal 10+ Integration

[![Search API Pantheon](https://github.com/pantheon-systems/search_api_pantheon/actions/workflows/ci.yml/badge.svg?branch=8.x)](https://github.com/pantheon-systems/search_api_pantheon/actions/workflows/ci.yml)
[![Actively Maintained](https://img.shields.io/badge/Pantheon-Actively_Maintained-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#actively-maintained-support)

## Table of Contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Setup](#setup)
- [Schema Updates](#schema-updates)
- [Core Reloading](#core-reloading)
- [Pantheon Environments](#pantheon-environments)
- [Diagnostic Commands](#diagnostic-commands)
- [Troubleshooting](#troubleshooting)
- [Upgrading](#upgrading)
- [Solr Jargon](#solr-jargon)
- [Feedback and Collaboration](#feedback-and-collaboration)

## Overview

This module provides [Drupal 10+](https://drupal.org) integration with [Apache Solr](https://solr.apache.org/) (versions 8 and 9) on [Pantheon](https://pantheon.io)'s Platform, simplifying the usage of [Search API](https://www.drupal.org/project/search_api) and [Search API Solr](https://www.drupal.org/project/search_api_solr).

Search API Solr provides the ability to connect to any Solr server by providing numerous configuration options. This module automatically sets the Solr connection options by extending the plugin from Search API Solr. The module also changes its connection information based on different Pantheon environments and each Pantheon Environment has its own [Solr Core](#solr-jargon). Doing so eliminates the need to do extra work setting up Solr servers for each environment.

### Solr Versions on Pantheon

- Solr 8: Apache Solr 8.11.4
- Solr 9: Apache Solr 9.10.0

## Requirements

- Drupal 10 or 11
- PHP 8.1 or later
- Hosting on Pantheon's platform
- Composer-based workflow using one of the following:
  - Pantheon's integrated composer (`build step: true` in your pantheon.yml)
  - A Continuous Integration service like Circle CI or Travis
- Dashboard access to the platform (necessary to deploy code changes)

## Installation

### Stable Release

```bash
composer require 'drupal/search_api_pantheon:^8'
```

### Beta Release (Solr 9 Support)

```bash
composer require 'drupal/search_api_pantheon:^8.5@beta'
```

### Development Version

```bash
composer require 'drupal/search_api_pantheon:8.5.x-dev@dev'
```

## Setup

### Enable Solr on Pantheon

Under "Settings" in your Pantheon site dashboard, enable Solr as an add on. This feature is available for sandbox sites as well as paid plans at the Professional level and above.

### Configure pantheon.yml

Add or update the following in your `pantheon.yml` file:

```yaml
search:
  version: 9
```

Or, to use Solr 8:

```yaml
search:
  version: 8
```

As you promote the code, the `pantheon.yml` file will follow the code through environments enabling the Solr server. However you will need to create an index for each environment and ensure the content is indexed after creation. Indices are specific to the Solr core with/for which they were created. Indices cannot be exported or moved once created.

### Enable the Modules

- Go to `admin/modules` and enable "Search API Pantheon."
- Doing so will also enable Search API and Search API Solr if they are not already enabled.

#### OPTIONAL: Disable Drupal Core's search module

If you are using Search API, you probably will not be using Drupal Core's Search module. Uninstall it to save some confusion in the further configuration steps: `admin/modules/uninstall`.

### Verify Installation

- Navigate in the Drupal interface to `CONFIG` => `SEARCH & METADATA` => `SEARCH API`
- Validate that the `PANTHEON SEARCH` server and Primary Index exist and are "enabled".

### Use the Server with an Index

When you enable the Search API Pantheon module, a **Primary** index is automatically created and linked to the Pantheon search server. You can use this default index or create your own custom index.

**To use the default 'Primary' index:**

- Go to `admin/config/search/search-api` and select the "Primary" index
- Configure fields to be indexed by selecting the "Fields" tab
- Add fields you want to search (e.g., "Title", "Body", etc.)
- Click "Save" to save your field configuration
- Post the schema (see [Schema Updates](#schema-updates) below)
- Click "Index now" to populate the index with your content. Alternatively, content will be indexed automatically when cron runs.

**To create a custom index:**

- Go to `admin/config/search/search-api/add-index`
- Name your index and choose a data source. If this is your first time using Search API, start by selecting "Content" as a data source. That option will index the articles, basic pages, and other node types you have configured.
- Select "Pantheon Search" as the server
- Save the index
- Configure fields to be searched by selecting the "Fields" tab. You may want to index many fields. "Title" is a good field to start with.
- Click "Save" to save your field configuration
- Post the schema (see [Schema Updates](#schema-updates) below)
- Click "Index now" to populate the index with your content. Alternatively, content will be indexed automatically when cron runs.

### Search the Index

- Create a new view using the search index of type 'Index'.
- Add fields to be included in the search results. Include the 'relevance' field to enable sorting by relevance.
- Expose the search keywords filter to allow users to enter search terms.
- Sort results by the "relevance" field. Solr assigns higher relevance ratings to documents that better match the search terms.

### Export Your Changes

It is a best practice in Drupal to export your changes to `yml` files.
Using Terminus while in SFTP mode, you can run `terminus drush [PANTHEON_SITE].[PANTHEON_ENV] -- "config:export -y"` to export the configuration changes you have made. Once committed, these changes can be deployed out to Test and Live environments.

## Schema Updates

When you first create your index or alter it significantly, you will need to update the SCHEMA on the server.

Schema updates can be performed using:

```bash
terminus drush <site>.<env> -- search-api-pantheon:postSchema [path]
```

The `[path]` argument is optional. Provide it only if you want to use a custom config-set directory. If omitted, the module uses Search API Solr to generate config files that match your installed module version and Solr version.

> **Note:** When Pantheon provisions a new Solr core, it loads a default schema that may differ from the schema version generated by your installed `search_api_solr` module. Running `drush search-api-pantheon:postSchema` after installation ensures the schema on the Solr server matches your module version, which is required for correct search behavior.

## Core Reloading

### Automatic Core Reload

Search API Pantheon automatically reloads the Solr core after schema updates to prevent schema reversions and maintain index integrity.

### Manual Core Reload

If needed, manually reload the core using:

```bash
terminus drush <site>.<env> -- search-api-solr:reload
```

## Pantheon Environments

Each Pantheon environment (Dev, Test, Live, and Multidevs) has its own Solr server. Indexing and searching in one environment does not impact any other environment.

When you enable the Search API Pantheon module, 'Pantheon Search' server is automatically installed by default. The Pantheon connector supports only a single Search API server per environment. Creating additional servers using the Pantheon connector will cause all servers to point to the same Solr core, which may result in schema conflicts or unexpected indexing behavior.

## Diagnostic Commands

Diagnostic commands automatically use the first server connected via Pantheon connector. The server_id argument is no longer needed or accepted.

| Command | Alias | Arguments | Description |
|---------|-------|-----------|-------------|
| `drush search-api-pantheon:diagnose` | `sapd` | None | Checks the various pieces of the Search API install and throws errors on pieces that are not working. |
| `drush search-api-pantheon:select` | `saps` | `<query>` (required) | Runs the given query against Solr server. Use `?debug=true` in any Solr page to get a good query to pass to this command. |
| `drush search-api-pantheon:force-cleanup` | `sapfc` | None | Deletes all of the contents for the Solr server (no matter if hash or index_id have changed). |
| `drush search-api-pantheon:postSchema` | `sapps` | `[path]` (optional) | Uploads schema files to the solr server. Can reset a schema to default, upgrade a schema, or use a custom config set. |
| `drush search-api-pantheon:test-index-and-query` | `sap-tiq` | None | Connects to the search server, indexes a single item, and immediately queries it. |

## Troubleshooting

### Common Issues

| Issue                       | Solution                                      |
| --------------------------- | --------------------------------------------- |
| Schema reverts unexpectedly | Ensure core reload is happening after updates |
| Search index corruption     | Try reposting schema and reindexing content   |
| Core reload failures        | Check Solr logs and connection status         |

## Upgrading

Upgrading from an earlier version? See [UPGRADE.md](UPGRADE.md) for detailed migration guides:

- [Upgrading from 8.4.x to 8.5.x](UPGRADE.md#upgrading-from-84x-to-85x)
- [Upgrading from Solr 8 to Solr 9](UPGRADE.md#upgrading-from-solr-8-to-solr-9)

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

## Feedback and Collaboration

Bug reports, feature requests, and feedback should be posted in [the drupal.org issue queue.](https://www.drupal.org/project/issues/search_api_pantheon?categories=All) For code changes, please submit pull requests against the [GitHub repository](https://github.com/pantheon-systems/search_api_pantheon).
