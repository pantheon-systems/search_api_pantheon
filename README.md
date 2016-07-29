# Search API Pantheon

[![CircleCI](https://circleci.com/gh/pantheon-systems/search_api_pantheon/tree/8.x-1.x.svg?style=svg)](https://circleci.com/gh/pantheon-systems/search_api_pantheon/tree/8.x-1.x)



This module is meant to simplify the usage of Search API and Search API Solr on Pantheon. Search API Solr provides the ability to 


## What does this module do?


## How it works

### Solr Versions and Schemas.

Currently, the version of Solr on Pantheon is Solr 3. Officially, the lowest supported version of Solr in Search API Solr is Solr 4. Pantheon will soon offer a higher version of Solr. Until it does, you can use the schema for Solr 4 that Search API Pantheon provides.

### Pantheon environments

Each Pantheon environment (Dev, Test, Live, and Multidevs) has its own Solr server. So indexing and searching in one environment does not impact any other environment. This also means that the schema file has to be posted to each environment's Solr server. Do to so, resave the search API server. [Before this module is released as a Beta, reposting will be done automatically.](https://www.drupal.org/node/2775549)

### Feedback and collaboration

Bug reports, feature requests, and feedback should be posted in [the Drupal.org issue queue.](https://www.drupal.org/project/issues/search_api_pantheon?categories=All) For code changes, please submit pull requests against the [GitHub repository](https://github.com/pantheon-systems/search_api_pantheon) rather than posting patches to drupal.org.

