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
 * Helper for resolving the internal adaptive-quiz scale a learning path
 * expects and checking it against the quizzes present in a course.
 *
 * @package     mod_adele
 * @author      Jacob Viertel
 * @copyright  2024 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adele;

/**
 * Class local_adele.
 *
 * @package     mod_adele
 * @author      Jacob Viertel
 * @copyright  2024 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_adele {
    /**
     * Constructor.
     */
    public function __construct() {
    }

    /**
     * Resolve the internal catquiz scale referenced by a learning path and
     * check it against the adaptive quizzes present in the given course.
     *
     * @param string $learningpathid The ID of the learning path.
     * @param string $courseid       The ID of the course.
     * @return array Result with keys 'alisecompatible' (bool) and 'msg' (string).
     */
    public static function get_internal_quiz_id($learningpathid, $courseid) {
        global $DB;
        $internalcatquizid = null;
        $alisecompatible = [
          'alisecompatible' => true,
          'msg' => '',
        ];
        $learningpathlocal = $DB->get_record(
            'local_adele_learning_paths',
            [
            'id' => $learningpathid,
            ],
            'json'
        );
        if ($learningpathlocal) {
            $learningpathlocal->json = json_decode($learningpathlocal->json);
            if (isset($learningpathlocal->json->tree->nodes)) {
                foreach ($learningpathlocal->json->tree->nodes as $node) {
                    if (isset($node->completion) && $node->completion->nodes) {
                        foreach ($node->completion->nodes as $completionnode) {
                            if (
                                isset($completionnode->data->label) &&
                                $completionnode->data->label == 'catquiz' &&
                                isset($completionnode->data->value->testid) &&
                                $completionnode->data->value->testid == '0'
                            ) {
                                if ($internalcatquizid && $internalcatquizid != $completionnode->data->value->parentscales) {
                                    $alisecompatible['msg'] = get_string('error_diverse_scales', 'mod_adele');
                                    $internalcatquizid = 0;
                                    break;
                                }
                                $internalcatquizid = $completionnode->data->value->parentscales;
                            }
                        }
                        if ($alisecompatible['msg']) {
                            break;
                        }
                    }
                }
            }
        }
        if ($alisecompatible['msg']) {
            $alisecompatible['alisecompatible'] = false;
        } else {
            $alisecompatible = self::get_alise_compability($internalcatquizid, $courseid);
        }

        return $alisecompatible;
    }

    /**
     * Check that the course contains exactly one adaptive quiz and that it
     * uses the scale expected by the learning path.
     *
     * @param string $internalcatquizid The ID of the internal catquiz scale.
     * @param string $courseid          The ID of the course.
     * @return array Result with keys 'alisecompatible' (bool) and 'msg' (string).
     */
    public static function get_alise_compability($internalcatquizid, $courseid) {
        global $DB;
        $alisecompatible = [
          'alisecompatible' => true,
          'msg' => '',
        ];
        if ($internalcatquizid) {
            $alisecount = 0;
            $modules = get_course_mods($courseid);
            foreach ($modules as $module) {
                if ($module->modname == 'adaptivequiz' && $module->deletioninprogress == 0) {
                    $adaptivetest = $DB->get_record(
                        'local_catquiz_tests',
                        ['id' => $module->instance],
                        'catscaleid'
                    );
                    if (empty($adaptivetest) || $adaptivetest->catscaleid != $internalcatquizid) {
                        $alisecompatible = [
                          'alisecompatible' => false,
                          'msg' => get_string('error_adaptivequiz_mismatch', 'mod_adele'),
                        ];
                    }
                    $alisecount++;
                }
            }
            if ($alisecount > 1 && $alisecompatible) {
                $alisecompatible = [
                  'alisecompatible' => false,
                  'msg' => get_string('error_multiple_adaptivequiz', 'mod_adele'),
                ];
            }
        }
        return $alisecompatible;
    }
}
