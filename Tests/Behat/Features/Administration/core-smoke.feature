Feature: Core administration is accessible
  
 @smoke-test
  Scenario: Open the Core logs page
    Given I log in to the page "exface.core.logs.html" as "Superuser: access everything" with locale "de_DE"
    Then I see 1 widget of type "DataTable"