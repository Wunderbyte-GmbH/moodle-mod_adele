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
 * Contains function with the definition of upgrade steps for the plugin.
 *
 * @package   mod_adele
 * @copyright 2024 Wunderbyte
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines upgrade steps for the plugin.
 *
 * @param mixed $oldversion
 * @return bool True on success.
 */
function xmldb_adele_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();
    if ($oldversion < 2024022100) {
        // Changing type of field participantslist on table adele to char.
        $table = new xmldb_table('adele');
        $field = new xmldb_field('participantslist', XMLDB_TYPE_CHAR, '256', null, XMLDB_NOTNULL, null, '0', 'userlist');

        // Launch change of type for field participantslist.
        $dbman->change_field_type($table, $field);

        // Adele savepoint reached.
        upgrade_mod_savepoint(true, 2024022100, 'adele');
    }

    if ($oldversion < 2025030400) {
        $table = new xmldb_table('adele');
        $field = new xmldb_field(
            'completionlearningpathfinished',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'introformat'
        );

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2025030400, 'adele');
    }

    return true;
}
