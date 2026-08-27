@local_artqtml @javascript
Feature: Deleting an ArtQTML generation
  In order to discard unused drafts
  As a teacher
  I need to delete a generation that has not been moved to a question bank

  Background:
    Given the ArtQTML plugin is ready for teachers
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Tina      | Teacher  |
    And the following "system role assigns" exist:
      | user     | role           |
      | teacher1 | editingteacher |
    And the following "local_artqtml > generations" exist:
      | user     | name        | shortname | status    |
      | teacher1 | Disposable  | DEL1      | completed |
    And the following "local_artqtml > questions" exist:
      | generation | questioncode | questiontext               | validationsuggestion |
      | Disposable | DEL1-IH-0001 | Draft question to discard. | accepted             |
    And I log in as "teacher1"

  Scenario: Teacher deletes a generation from the list
    When I visit the ArtQTML list page
    And I click on "Delete" "button" in the "Disposable" "table_row"
    And I click on "Yes" "button" in the "Confirmation" "dialogue"
    Then I should see "and all of its questions were deleted."
    And I should not see "DEL1-IH-0001"
