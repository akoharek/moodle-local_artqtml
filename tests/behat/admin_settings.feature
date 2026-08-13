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
    When I press "Save changes"
    Then I should see "Changes saved"

  Scenario: Administrator sees the missing API key notice on the list
    Given I log in as "admin"
    When I visit the ArtQTML list page
    Then I should see "The Claude and Gemini API keys are missing or could not be decrypted"
