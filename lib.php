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
 * lib.php
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\exception\moodle_exception;
use local_kopere_wpbridge\service\webhook_manager;

/**
 * Add a shortcut to the mapping page in course navigation.
 *
 * @param navigation_node $navigation Navigation node instance.
 * @param stdClass $course Course record.
 * @param context_course $context Course context.
 * @return void
 * @throws moodle_exception
 * @throws coding_exception
 */
function local_kopere_wpbridge_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context
): void {
    if (!has_capability("local/kopere_wpbridge:manage", $context)) {
        return;
    }

    $url = new moodle_url("/local/kopere_wpbridge/mappings.php", [
        "courseid" => $course->id,
    ]);

    $navigation->add(
        get_string("wpbridge", "local_kopere_wpbridge"),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        "local_kopere_wpbridge"
    );
}

/**
 * Run connection test and webhook setup after saving the settings page.
 *
 * @return void
 * @throws Exception
 */
function local_kopere_wpbridge_after_settings_save(): void {
    webhook_manager::after_settings_save();
}
