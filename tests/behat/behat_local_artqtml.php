<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Behat steps for local_artqtml.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

$behatbase = __DIR__ . '/../../../../lib/behat/behat_base.php';
if (!is_file($behatbase)) {
    $behatbase = __DIR__ . '/../../../../../lib/behat/behat_base.php';
}
require_once($behatbase);

use Behat\Mink\Exception\ExpectationException;

/**
 * Behat steps for local_artqtml.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_artqtml extends behat_base {
    /**
     * Enable the plugin with a draft course, models and dummy API keys.
     *
     * @Given the ArtQTML plugin is ready for teachers
     */
    public function the_plugin_is_ready_for_teachers(): void {
        $generator = testing_util::get_data_generator()->get_plugin_generator('local_artqtml');
        $generator->setup_for_teachers();
    }

    /**
     * Open the plugin generation list.
     *
     * @When I visit the ArtQTML list page
     */
    public function i_visit_the_list_page(): void {
        $this->execute('behat_general::i_visit', ['/local/artqtml/index.php']);
    }

    /**
     * Open the plugin General admin settings page.
     *
     * @When I visit the ArtQTML general settings page
     */
    public function i_visit_the_general_settings_page(): void {
        $this->execute('behat_general::i_visit', ['/admin/settings.php?section=local_artqtml_general']);
    }

    /**
     * Open the status or approve page that Open would use for this generation name.
     *
     * @When I open the ArtQTML generation named :name
     * @param string $name
     */
    public function i_open_the_generation_named(string $name): void {
        global $DB;

        $generation = $DB->get_record('local_artqtml_generations', ['name' => $name], '*', MUST_EXIST);
        $url = \local_artqtml\local\generation_list::open_url($generation);
        $this->execute('behat_general::i_visit', [$url->out_as_local_url(false)]);
    }

    /**
     * Seed a move-target question category in a course the teacher can add questions to.
     *
     * @Given the course :shortname has an ArtQTML move-target question category
     * @param string $shortname
     */
    public function the_course_has_a_move_target_category(string $shortname): void {
        global $DB;

        $courseid = (int) $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        $generator = testing_util::get_data_generator()->get_plugin_generator('local_artqtml');
        $generator->create_move_target_category($courseid);
    }

    /**
     * Pick the first non-empty option in the approve-page category select.
     *
     * @When I choose the first ArtQTML move target category
     */
    public function i_choose_the_first_move_target_category(): void {
        $select = $this->find('css', '[data-testid="artqtml-approve-category-select"]');
        $options = $select->findAll('css', 'option');
        foreach ($options as $option) {
            $value = (string) $option->getAttribute('value');
            if ($value !== '') {
                $select->selectOption($option->getText());
                return;
            }
        }

        throw new ExpectationException(
            'No ArtQTML move target category was available',
            $this->getSession()
        );
    }

    /**
     * Status.js reveals the failed-action region after the first poll.
     *
     * @Then the ArtQTML failed-generation actions should be visible
     */
    public function the_failed_generation_actions_should_be_visible(): void {
        $this->spin(function () {
            $node = $this->find('css', '[data-region="error"]');
            if (!$node->isVisible()) {
                throw new ExpectationException(
                    'ArtQTML failed-generation actions are still hidden',
                    $this->getSession()
                );
            }
            return true;
        });
    }
}
