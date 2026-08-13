@local_artqtml
Feature: ArtQTML admin settings
  In order to run the plugin
  As an administrator
  I need to open the settings and save them

  Scenario: Administrator opens General settings and saves
    Given I log in as "admin"
    When I visit the ArtQTML general settings page
    Then I should see "Enable plugin"
    And I should see "The Claude and Gemini API keys are missing or could not be decrypted"
    When I set the field "Enable plugin" to "0"
    And I press "Save changes"
    And I visit the ArtQTML general settings page
    Then the field "Enable plugin" matches value "0"

  Scenario: Administrator sees the missing API key notice on the list
    Given I log in as "admin"
    When I visit the ArtQTML list page
    Then I should see "The Claude and Gemini API keys are missing or could not be decrypted"
