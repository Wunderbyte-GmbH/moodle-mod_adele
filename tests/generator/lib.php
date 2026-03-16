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
 * Data generator for mod_adele.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Andrii Semenets
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Class mod_adele_generator for generation of dummy data.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Andrii Semenets
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_adele_generator extends testing_module_generator {
    /**
     * Create a new instance of the adele activity module.
     *
     * Sets default values for required fields that may not be provided
     * by the calling test.
     *
     * @param array|stdClass|null $record Data for the module instance.
     * @param array|null $options General options for course module.
     * @return stdClass The created module instance record.
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (array)($record ?? []);

        $record += [
            'learningpathid' => 0,
            'view' => 1,
            'userlist' => 0,
            'participantslist' => '0',
        ];

        return parent::create_instance($record, $options);
    }
}
