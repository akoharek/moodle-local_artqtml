@local_artqtml @javascript
Feature: Reviewing generated ArtQTML questions
  In order to put AI questions into a real question bank
  As a teacher
  I need to approve, edit, move and delete questions from a finished generation

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
    And the course "BIOBANK" has an ArtQTML move-target question category
    And the following "system role assigns" exist:
      | user     | role           |
      | teacher1 | editingteacher |
    And the following "local_artqtml > generations" exist:
      | user     | name         | shortname | status    |
      | teacher1 | Review pack  | REV1      | completed |
    And the following "local_artqtml > questions" exist:
      | generation  | questioncode  | questiontext                          | validationsuggestion |
      | Review pack | REV1-IH-0001  | Water is required for photosynthesis. | accepted             |
      | Review pack | REV1-IH-0002  | Plants do not contain chlorophyll.    | needs_review         |
    And I log in as "teacher1"

  Scenario: Teacher reviews and approves a question
    When I visit the ArtQTML list page
    And I click on "Open" "link" in the "Review pack" "table_row"
    Then I should see "Review and approve questions"
    And I should see "REV1-IH-0001"
    And I should see "Accepted"
    When I click on "REV1-IH-0001" "link" in the "REV1-IH-0001" "table_row"
    Then I should see "Correct answer:"
    And I should see "True"
    When I click on "Approve" "button" in the "REV1-IH-0001" "table_row"
    Then I should see "Approved"
    And I should see "Revoke"

  Scenario: Teacher revokes approval
    When I open the ArtQTML generation named "Review pack"
    And I click on "Approve" "button" in the "REV1-IH-0001" "table_row"
    And I click on "Revoke" "link" in the "REV1-IH-0001" "table_row"
    Then I should see "Approve"

  Scenario: Teacher approves all accepted questions
    When I open the ArtQTML generation named "Review pack"
    And I press "Approve all accepted (1)"
    Then I should see "1 question(s) approved."
    And I should see "Approved"

  Scenario: Teacher deletes a draft question
    When I open the ArtQTML generation named "Review pack"
    And I click on "Delete" "link" in the "REV1-IH-0002" "table_row"
    And I click on "Yes" "button" in the "Confirmation" "dialogue"
    Then I should not see "REV1-IH-0002"

  Scenario: Teacher edits a draft question in Moodle's editor
    When I open the ArtQTML generation named "Review pack"
    And I click on "Edit" "link" in the "REV1-IH-0001" "table_row"
    Then I should see "Question name"
    And the field "Question name" matches value "REV1-IH-0001"

  Scenario: Teacher can preview a draft question
    When I open the ArtQTML generation named "Review pack"
    Then "[data-testid='artqtml-approve-preview-link']" "css_element" should exist

  Scenario: Teacher moves an approved question into a course question bank
    When I open the ArtQTML generation named "Review pack"
    And I click on "Approve" "button" in the "REV1-IH-0001" "table_row"
    And I choose the first ArtQTML move target category
    And I click on "Move selected" "button" in the "REV1-IH-0001" "table_row"
    Then I should see "question(s) moved to the selected question bank"
    And I should see "Moved"
