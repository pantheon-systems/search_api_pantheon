Feature: Solr on Pantheon
# This feature file is not a representation of Behavior Driven Development.
# It is a series of simulated clicks and form interactions.

  @api
  Scenario: Create Solr server configuration
    Given I am logged in as a user with the "administrator" role
    When I visit "admin/config/search/search-api/add-server"
    And I fill in "name" with "pantheon"
    And I fill in "id" with "pantheon"
    # Many other fields are currently filled in by a Form Alter.
    # That will change https://github.com/stevector/search_api_pantheon/issues/4
    And I press the "Save" button
    When I visit "admin/config/search/search-api/server/pantheon"
    # Here is the real verification of this scenario, that the server can be
    # reached.
    Then I should see "The Solr server could be reached"
    Then I should see "The Solr core could be accessed (latency: "

  @api
  Scenario: Create Solr index configuration, index the title field.
    Given I am logged in as a user with the "administrator" role
    When I visit "admin/config/search/search-api/add-index"
    And I fill in "name" with "nodes"
    And I fill in "id" with "nodes"
    And I select "Content" from "datasources[]"
    And I select the radio button "pantheon"
    And I press the "Save" button
    When I visit "admin/config/search/search-api/index/nodes/fields/add?datasource=entity%3Anode"
    And I press the "entity:node/title" button
    When I visit "admin/config/search/search-api/index/nodes/fields"
    And I select "Fulltext" from "fields[title][type]"
    And I press the "Save" button

  @api
  Scenario: Create Search page
    Given I am logged in as a user with the "administrator" role
    When I visit "admin/config/search/search-api-pages/add"
    And I fill in "label" with "content-search"
    And I fill in "id" with "content_search"
    And I select "nodes" from "index"
    And I press the "Next" button
    When I visit "admin/config/search/search-api-pages/content_search"
    And I select "title" from "searched_fields[]"
    And I fill in "path" with "content-search"
    And I press the "Save" button

  @api
  Scenario: Create a test node, fill search index, search for node.
  # Behat will delete this node at the end of the run.
    Given I am logged in as a user with the "administrator" role
    When I visit "node/add/article"
    And I fill in "title[0][value]" with "Test article title"
    And I press the "Save and publish" button
    When I visit "admin/content"
    Then I should see the text "Test article"
    When I visit "admin/reports/status"
    And I follow "run cron manually"
    When I visit "admin/config/search/search-api/index/nodes"
    Then I should see "100%" in the "index_percentage" region
    # And I break
    When I visit "content-search"
    And I fill in "keys" with "Test article"
    And I press the "Search" button
    Then I should see the link "Test article title"
    Then I should not see the text "Your search yielded no results."