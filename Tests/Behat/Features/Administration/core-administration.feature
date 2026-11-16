
Feature: Core administration pages and tables render without errors

  @extended-test @core-administration
  Scenario Outline: Inspect the <section> administration table
    Given I log in to the page "<page>" as "Superuser: access everything" with locale "de_DE"
    Then I should see the page
    And I see 1 widget of type "DataTable" with "<object>"
    And I see at least one data item
    And I do not see any errors

    Examples:
      | section      | page                          | object                 |
      | apps         | exface.core.apps.html         | exface.Core.APP        |
      | objects      | exface.core.objects.html      | exface.Core.OBJECT     |
      | attributes   | exface.core.attributes.html   | exface.Core.ATTRIBUTE  |
      | data types   | exface.core.data-types.html   | exface.Core.DATATYPE   |
      | data sources | exface.core.data-sources.html | exface.Core.DATASRC    |
      | connections  | exface.core.connections.html  | exface.Core.CONNECTION |