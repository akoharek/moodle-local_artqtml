@local_artqtml
Feature: Approve page row actions before and after move
  In order to review draft questions safely
  As a teacher
  I need the correct action buttons on each approve row

  Background:
    Given the ArtQTML plugin is ready for teachers
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Tina      | Teacher  |
    And the following "courses" exist:
      | fullname     | shortname |
      | Biology bank | BIOBANK   |
    And the following "course enrolments" exist:
      | user     | course  | role           |
      | teacher1 | BIOBANK | editingteacher |
    And the following "system role assigns" exist:
      | user     | role           |
      | teacher1 | editingteacher |
    And the following "local_artqtml > generations" exist:
      | user     | name        | shortname | status    |
      | teacher1 | Action pack | ACT1      | completed |
    And I log in as "teacher1"

  Scenario: Unmoved draft row shows Preview only, not Edit or Open
    Given the following "local_artqtml > questions" exist:
      | generation  | questioncode  | questiontext                       | validationsuggestion | movedout |
      | Action pack | ACT1-IH-0001  | Plants need sunlight to grow.      | accepted             | 0        |
    When I open the ArtQTML generation named "Action pack"
    Then I should see "ACT1-IH-0001"
    And "Preview" "link" should exist in the "ACT1-IH-0001" "table_row"
    And "Edit" "link" should not exist in the "ACT1-IH-0001" "table_row"
    And "Open" "link" should not exist in the "ACT1-IH-0001" "table_row"

  Scenario: Moved row shows Open, not Edit or Preview
    Given the following "local_artqtml > questions" exist:
      | generation  | questioncode  | questiontext                       | validationsuggestion | movedout | movecourse |
      | Action pack | ACT1-IH-0002  | Chlorophyll captures light energy. | accepted             | 1        | BIOBANK    |
    When I open the ArtQTML generation named "Action pack"
    Then I should see "ACT1-IH-0002"
    And "Open" "link" should exist in the "ACT1-IH-0002" "table_row"
    And "Edit" "link" should not exist in the "ACT1-IH-0002" "table_row"
    And "Preview" "link" should not exist in the "ACT1-IH-0002" "table_row"
