#!/bin/bash

set -ex

# @todo, pull in upstream updates, wipe and install drupal.
# Also, it might be cleaner to create an entirely new D8 site rather than making
# multidevs off of the same one repeatedly.
terminus env:create  $TERMINUS_SITE.dev $TERMINUS_ENV

# @todo, this command gives an error
# [error] You must upgrade to a business or an elite plan to use Solr.
# Bug tracked here: https://github.com/pantheon-systems/terminus/issues/1118
#terminus site solr enable
