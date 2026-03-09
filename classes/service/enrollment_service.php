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
 * enrollment_service.php
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_kopere_wpbridge\service;

use coding_exception;
use dml_exception;
use moodle_exception;
use Random\RandomException;
use stdClass;

/**
 * Service responsible for ensuring users exist and applying Moodle access.
 */
class enrollment_service {
    /**
     * Find or create a Moodle user from order data.
     *
     * @param stdClass $order Mirrored order record.
     * @return stdClass
     * @throws RandomException
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function ensure_user_from_order(stdClass $order): stdClass {
        global $CFG, $DB;

        $email = strtolower(trim($order->email));
        if ($email == "") {
            throw new moodle_exception("error_missingemail", "local_kopere_wpbridge");
        }

        $user = $DB->get_record_select(
            "user",
            "deleted = 0 AND " . $DB->sql_compare_text("email") . " = " . $DB->sql_compare_text(":email"),
            ["email" => $email],
            "*",
            IGNORE_MULTIPLE
        );

        if ($user) {
            return $user;
        }

        require_once("{$CFG->dirroot}/user/lib.php");

        $firstname = trim($order->firstname);
        $lastname = trim($order->lastname);

        if ($firstname == "") {
            $firstname = "WooCommerce";
        }

        if ($lastname == "") {
            $lastname = "User";
        }

        $username = clean_param(substr($email, 0, 100), PARAM_USERNAME);
        if ($username == "") {
            $username = "wcuser" . time();
        }

        while ($DB->record_exists("user", ["username" => $username])) {
            $username = $username . random_int(1, 9);
        }

        $newuser = (object) [
            "auth" => "manual",
            "confirmed" => 1,
            "mnethostid" => $CFG->mnet_localhost_id,
            "username" => $username,
            "password" => random_string(24),
            "email" => $email,
            "firstname" => $firstname,
            "lastname" => $lastname,
            "timecreated" => time(),
            "timemodified" => time(),
        ];

        $userid = user_create_user($newuser, false, false);
        return $DB->get_record("user", ["id" => $userid], "*", MUST_EXIST);
    }

    /**
     * Apply a mapping to the user.
     *
     * @param int $userid Moodle user ID.
     * @param stdClass $mapping Mapping record.
     * @return string
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function apply_mapping(int $userid, stdClass $mapping): string {
        if ($mapping->itemtype == "course") {
            $this->enrol_in_course($userid, $mapping->courseid, $mapping->roleid);
            return "Course access granted: " . $mapping->courseid;
        }

        if ($mapping->itemtype == "cohort") {
            $this->add_to_cohort($userid, $mapping->cohortid);
            return "Cohort membership granted: " . $mapping->cohortid;
        }

        throw new coding_exception("Unknown mapping type.");
    }

    /**
     * Enrol the user in a course using the manual enrolment instance.
     *
     * @param int $userid Moodle user ID.
     * @param int $courseid Course ID.
     * @param int $roleid Role ID.
     * @return void
     * @throws coding_exception
     * @throws moodle_exception
     */
    protected function enrol_in_course(int $userid, int $courseid, int $roleid): void {
        global $CFG;

        require_once("{$CFG->dirroot}/enrol/locallib.php");

        $instances = enrol_get_instances($courseid, true);
        $manualinstance = null;

        foreach ($instances as $instance) {
            if ($instance->enrol == "manual" && $instance->status == ENROL_INSTANCE_ENABLED) {
                $manualinstance = $instance;
                break;
            }
        }

        if (!$manualinstance) {
            throw new moodle_exception("error_nomanualenrol", "local_kopere_wpbridge");
        }

        $plugin = enrol_get_plugin("manual");
        $plugin->enrol_user($manualinstance, $userid, $roleid);
    }

    /**
     * Add the user to a cohort if not already a member.
     *
     * @param int $userid Moodle user ID.
     * @param int $cohortid Cohort ID.
     * @return void
     * @throws dml_exception
     */
    protected function add_to_cohort(int $userid, int $cohortid): void {
        global $DB, $CFG;

        require_once("{$CFG->dirroot}/cohort/lib.php");

        if ($DB->record_exists("cohort_members", ["cohortid" => $cohortid, "userid" => $userid])) {
            return;
        }

        cohort_add_member($cohortid, $userid);
    }
}
