@local_artqtml @javascript
Feature: ArtQTML generation status
  In order to know what a generation is doing
  As a teacher
  I need to see the right status page and actions

  Background:
    Given the ArtQTML plugin is ready for teachers
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Tina      | Teacher  |
    And the following "system role assigns" exist:
      | user     | role           |
      | teacher1 | editingteacher |
    And I log in as "teacher1"

  Scenario: Completed generation shows the success notice
    Given the following "local_artqtml > generations" exist:
      | user     | name             | shortname | status    |
      | teacher1 | Completed pack   | COMP1     | completed |
    When I open the ArtQTML generation named "Completed pack"
    Then I should see "Review and approve questions"

  Scenario: Failed generation shows Retry and Back to settings
    Given the following "local_artqtml > generations" exist:
      | user     | name          | shortname | status | error                    |
      | teacher1 | Failed pack   | FAIL1     | failed | Behat fixture API error  |
    When I open the ArtQTML generation named "Failed pack"
    Then the ArtQTML failed-generation actions should be visible
    And I should see "Retry"
    And I should see "Back to settings"
    And ".artqtml-buttonrow" "css_element" should exist

  Scenario: In-progress generation shows Abort
    Given the following "local_artqtml > generations" exist:
      | user     | name            | shortname | status      |
      | teacher1 | Running pack    | RUN1      | generating  |
    When I visit the ArtQTML list page
    And I click on "Open" "link" in the "Running pack" "table_row"
    Then I should see "Generating questions"
    And I should see "Abort"

  Scenario: Partial generation offers to generate the missing types
    Given the following "local_artqtml > generations" exist:
      | user     | name           | shortname | status  | settings                                         | countdiscrepancy                                              | sourcetext                          |
      | teacher1 | Partial pack   | PART1     | partial | {"knowledgesource":"sourceonly","matrix_FE_easy":2} | [{"type":"FE","requested":2,"received":0}]                    | Unique partial source about mitochondria. |
    And the following "local_artqtml > questions" exist:
      | generation   | questioncode  | questiontext                         | validationsuggestion |
      | Partial pack | PART1-IH-0001 | Mitochondria produce ATP.            | accepted             |
    When I visit the ArtQTML list page
    And I click on "Open" "link" in the "Partial pack" "table_row"
    Then I should see "This generation finished, but produced fewer questions"
    And I should see "Generate the missing types"
    When I press "Generate the missing types"
    And I click on "Yes" "button" in the "Confirmation" "dialogue"
    And I press "Continue anyway"
    Then I should see "Start generation"
