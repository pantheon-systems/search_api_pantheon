#!/bin/bash

set -x

terminus upstream:updates:apply $TERMINUS_SITE
terminus env:create $TERMINUS_SITE.dev $TERMINUS_ENV

# It's not really necessary to enable solr over and over.
terminus solr:enable $TERMINUS_SITE
