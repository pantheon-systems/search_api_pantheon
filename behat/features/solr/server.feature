Feature: Solr

  @api
  Scenario: Solr server
    Given I am logged in as a user with the "administrator" role
    When I visit "admin/config/search/search-api/server/pantheon"
		Then print last response
		Then I should see "The Solr server could be reached"
		Then I should see "The Solr core could be accessed (latency: "
