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
 * Behat data generator for local_artqtml.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Behat data generator for local_artqtml.
 *
 * @package    local_artqtml
 * @category   test
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_artqtml_generator extends behat_generator_base {

    /**
     * Entities this plugin can create from Gherkin tables.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'generations' => [
                'singular'      => 'generation',
                'datagenerator' => 'generation',
                'required'      => ['name'],
                'switchids'     => ['user' => 'userid'],
            ],
            'questions' => [
                'singular'      => 'question',
                'datagenerator' => 'question',
                'required'      => ['generation', 'questioncode'],
                'switchids'     => ['generation' => 'generationid'],
            ],
        ];
    }

    /**
     * Look up a generation id from its name.
     *
     * @param string $name
     * @return int
     */
    protected function get_generation_id(string $name): int {
        global $DB;

        $id = $DB->get_field('local_artqtml_generations', 'id', ['name' => $name]);
        if (!$id) {
            throw new Exception('There is no ArtQTML generation named "' . $name . '"');
        }

        return (int) $id;
    }
}
