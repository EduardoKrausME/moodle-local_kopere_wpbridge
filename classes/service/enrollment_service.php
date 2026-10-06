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
use core\lock\lock_config;
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
     * WooCommerce customer_id is the preferred identity once the bridge has linked that customer
     * to a Moodle user through a previous processed order. Email is only the fallback/bootstrap key.
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

        $customerid = (int) ($order->customerid ?? 0);
        $identitykey = $customerid > 0 ? "customer_" . $customerid : "email_" . sha1($email);
        $factory = lock_config::get_lock_factory("local_kopere_wpbridge");
        $lock = $factory->get_lock("identity_" . $identitykey, 10);

        if (!$lock) {
            throw new moodle_exception("error_locktimeout", "local_kopere_wpbridge");
        }

        try {
            if ($customerid > 0) {
                $userid = $DB->get_field_sql(
                    "SELECT i.userid
                       FROM {local_kopere_wpbridge_item} i
                       JOIN {local_kopere_wpbridge_order} o ON o.id = i.orderid
                       JOIN {user} u ON u.id = i.userid
                      WHERE o.customerid = :customerid
                        AND i.userid > 0
                        AND u.deleted = 0
                   ORDER BY i.timemodified DESC",
                    ["customerid" => $customerid],
                    IGNORE_MULTIPLE
                );

                if ($userid) {
                    return $DB->get_record("user", ["id" => $userid], "*", MUST_EXIST);
                }
            }

            $users = $DB->get_records_select(
                "user",
                "deleted = 0 AND " . $DB->sql_compare_text("email") . " = " . $DB->sql_compare_text(":email"),
                ["email" => $email],
                "id ASC",
                "*",
                0,
                2
            );

            if (count($users) > 1) {
                throw new moodle_exception("error_duplicateemail", "local_kopere_wpbridge", "", $email);
            }

            if ($users) {
                return reset($users);
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
        } finally {
            $lock->release();
        }
    }

    /**
     * Apply a mapping to the user.
     *
     * @param int $userid Moodle user ID.
     * @param stdClass $mapping Mapping record.
     * @return array Grant metadata.
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function apply_mapping(int $userid, stdClass $mapping): array {
        if ($mapping->itemtype == "course") {
            $created = $this->enrol_in_course($userid, $mapping->courseid, $mapping->roleid);
            return [
                "message" => "Course access granted: " . $mapping->courseid,
                "itemtype" => "course",
                "targetid" => (int) $mapping->courseid,
                "roleid" => (int) $mapping->roleid,
                "accesscreated" => $created,
            ];
        }

        if ($mapping->itemtype == "cohort") {
            $created = $this->add_to_cohort($userid, $mapping->cohortid);
            return [
                "message" => "Cohort membership granted: " . $mapping->cohortid,
                "itemtype" => "cohort",
                "targetid" => (int) $mapping->cohortid,
                "roleid" => 0,
                "accesscreated" => $created,
            ];
        }

        throw new coding_exception("Unknown mapping type.");
    }

    /**
     * Revoke access previously created by this bridge.
     *
     * @param int $userid Moodle user ID.
     * @param array $grant Stored grant metadata.
     * @return bool True when an access record was removed.
     * @throws moodle_exception
     */
    public function revoke_grant(int $userid, array $grant): bool {
        if (empty($grant["accesscreated"])) {
            return false;
        }

        if (($grant["itemtype"] ?? "") == "course") {
            return $this->unenrol_from_course($userid, (int) ($grant["targetid"] ?? 0));
        }

        if (($grant["itemtype"] ?? "") == "cohort") {
            return $this->remove_from_cohort($userid, (int) ($grant["targetid"] ?? 0));
        }

        return false;
    }

    /**
     * Enrol the user in a course using the manual enrolment instance.
     *
     * @param int $userid Moodle user ID.
     * @param int $courseid Course ID.
     * @param int $roleid Role ID.
     * @return bool Whether this bridge created the manual enrolment.
     * @throws coding_exception
     * @throws moodle_exception
     */
    protected function enrol_in_course(int $userid, int $courseid, int $roleid): bool {
        global $CFG, $DB;

        require_once("{$CFG->dirroot}/enrol/locallib.php");

        $manualinstance = $this->get_manual_instance($courseid);
        $alreadyexists = $DB->record_exists("user_enrolments", [
            "enrolid" => $manualinstance->id,
            "userid" => $userid,
        ]);

        $plugin = enrol_get_plugin("manual");
        $plugin->enrol_user($manualinstance, $userid, $roleid);

        return !$alreadyexists;
    }

    /**
     * Remove a manual enrolment created by the bridge.
     *
     * @param int $userid Moodle user ID.
     * @param int $courseid Course ID.
     * @return bool
     * @throws moodle_exception
     */
    protected function unenrol_from_course(int $userid, int $courseid): bool {
        global $CFG, $DB;

        require_once("{$CFG->dirroot}/enrol/locallib.php");

        $manualinstance = $this->get_manual_instance($courseid);
        if (!$DB->record_exists("user_enrolments", [
            "enrolid" => $manualinstance->id,
            "userid" => $userid,
        ])) {
            return false;
        }

        $plugin = enrol_get_plugin("manual");
        $plugin->unenrol_user($manualinstance, $userid);
        return true;
    }

    /**
     * Return the enabled manual enrolment instance for a course.
     *
     * @param int $courseid Course ID.
     * @return stdClass
     * @throws moodle_exception
     */
    protected function get_manual_instance(int $courseid): stdClass {
        $instances = enrol_get_instances($courseid, true);

        foreach ($instances as $instance) {
            if ($instance->enrol == "manual" && $instance->status == ENROL_INSTANCE_ENABLED) {
                return $instance;
            }
        }

        throw new moodle_exception("error_nomanualenrol", "local_kopere_wpbridge");
    }

    /**
     * Add the user to a cohort if not already a member.
     *
     * @param int $userid Moodle user ID.
     * @param int $cohortid Cohort ID.
     * @return bool Whether this bridge created the membership.
     * @throws dml_exception
     */
    protected function add_to_cohort(int $userid, int $cohortid): bool {
        global $DB, $CFG;

        require_once("{$CFG->dirroot}/cohort/lib.php");

        if ($DB->record_exists("cohort_members", ["cohortid" => $cohortid, "userid" => $userid])) {
            return false;
        }

        cohort_add_member($cohortid, $userid);
        return true;
    }

    /**
     * Remove a cohort membership created by the bridge.
     *
     * @param int $userid Moodle user ID.
     * @param int $cohortid Cohort ID.
     * @return bool
     */
    protected function remove_from_cohort(int $userid, int $cohortid): bool {
        global $DB, $CFG;

        require_once("{$CFG->dirroot}/cohort/lib.php");

        if (!$DB->record_exists("cohort_members", ["cohortid" => $cohortid, "userid" => $userid])) {
            return false;
        }

        cohort_remove_member($cohortid, $userid);
        return true;
    }
}
