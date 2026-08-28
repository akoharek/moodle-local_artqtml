@local @local_artqtml
Feature: Starting a new ArtQTML generation
  In order to generate questions from source text
  As a teacher
  I need to reach the generation settings page without calling an AI service

  Background:
    Given the ArtQTML plugin is ready for teachers
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Tina      | Teacher  |
    And the following "system role assigns" exist:
      | user     | role           |
      | teacher1 | editingteacher |
    And I log in as "teacher1"

  Scenario: Teacher pastes source text and reaches question settings
    When I visit the ArtQTML list page
    And I follow "New generation"
    Then I should see "Identifiers"
    And I should see "Source text"
    When I set the field "Generation name" to "Photosynthesis pack"
    And I set the field "Shortname" to "PHOTO1"
    And I set the field "Source text" to "Photosynthesis converts light energy into chemical energy in plants."
    And I press "Continue"
    Then I should see "Step 1: difficulty mode and count"
    And I should see "True/False"
    And I should see "Single choice"
    And I should see "Ordering"
    And I should see "Start generation"
