# Search API Pantheon version 8.0 (for solr 8 & Drupal 8/9)

[![Search API Pantheon](https://github.com/pantheon-systems/search_api_pantheon/actions/workflows/ci.yml/badge.svg?branch=8.x)](https://github.com/pantheon-systems/search_api_pantheon/actions/workflows/ci.yml)

![Solr at pantheon diagram](docs/diagram.svg "Solr at pantheon")

## Requirements

This module is for you if you meet the following requirements:

* Using Drupal 8.8/9.2+

* Hosting the Drupal site on Pantheon's platform

* Your site uses `composer` to install modules and upgrade Drupal core using one of the following integrations:

  * Pantheon's integrated composer (`build step: true` in your pantheon.yml)

  * A Continuous Integration service like Circle CI or Travis

* Have Dashboard access to the platform (necessary to deploy code changes)

## Intent

This module is meant to simplify the usage of [Search API](https://www.drupal.org/project/search_api)
and [Search API Solr](https://www.drupal.org/project/search_api_solr) on [Pantheon](https://pantheon.io)'s Platform.

Search API Solr provides the ability to connect to any Solr server by providing numerous configuration options.
This module automatically sets the Solr connection options by extending the plugin from Search API Solr.
The module also changes its connection information based on different Pantheon environments and each
Pantheon Environment has it's own SOLR CORE. Doing so eliminates the need to do extra work setting
up Solr servers for each environment.

## What it provides

This module provides [Drupal 9](https://drupal.org) integration with the [Apache Solr project](https://solr.apache.org/guide/8_8/).
Pantheon's current version as of the update of this document is 8.8.1.

### For more information, [please visit our wiki on Github](https://github.com/pantheon-systems/search_api_pantheon/wiki)
