@local_artqtml
Feature: ArtQTML access and blocking
  In order to keep draft AI content away from the wrong people
  As a teacher or student
  I need the plugin pages to honour capabilities and admin switches

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Tina      | Teacher  |
      | student1 | Sam       | Student  |
    And the following "system role assigns" exist:
      | user     | role           |
      | teacher1 | editingteacher |

  Scenario: Teacher sees the list and a student cannot
    Given the ArtQTML plugin is ready for teachers
    And I log in as "teacher1"
    When I visit the ArtQTML list page
    Then I should see "Generate quiz questions with AI"
    And I should see "New generation"
    And I log out
    And I log in as "student1"
    And I am on homepage
    Then "ArtQTML" "link" should not exist
    And I should not see "New generation"

  Scenario: Disabled plugin shows the administrator notice
    Given the ArtQTML plugin is ready for teachers
    And the following config values are set as admin:
      | enabled | 0 | local_artqtml |
    And I log in as "teacher1"
    When I visit the ArtQTML list page
    Then I should see "The ArtQTML is currently disabled by the site administrator."
    And "New generation" "link" should not exist

  Scenario: Missing generator model blocks a new generation
    Given the following config values are set as admin:
      | enabled | 1 | local_artqtml |
    And I log in as "teacher1"
    When I visit the ArtQTML list page
    Then I should see "No generator model is configured"
    And "New generation" "link" should not exist

  Scenario: Missing draft course blocks a new generation
    Given the ArtQTML plugin is ready for teachers
    And the following config values are set as admin:
      | draftcourseid | 0 | local_artqtml |
    And I log in as "teacher1"
    When I visit the ArtQTML list page
    Then I should see "The draft course is not configured or no longer exists"
    And "New generation" "link" should not exist
