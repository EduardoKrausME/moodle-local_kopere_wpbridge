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
 * mapping_form.php
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_kopere_wpbridge\form;

use coding_exception;
use dml_exception;
use moodleform;

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
require_once("{$CFG->libdir}/formslib.php");

/**
 * Form used to bind WooCommerce products to Moodle courses or cohorts.
 */
class mapping_form extends moodleform {
    /**
     * Define the form fields.
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     */
    public function definition(): void {
        $mform = $this->_form;
        $data = $this->_customdata["data"] ?? null;

        $mform->addElement("hidden", "id");
        $mform->setType("id", PARAM_INT);

        $mform->addElement(
            "text",
            "productid",
            get_string("mapping_productid", "local_kopere_wpbridge")
        );
        $mform->setType("productid", PARAM_INT);
        $mform->addRule("productid", null, "required", null, "client");

        $types = [
            "course" => get_string("mapping_itemtype_course", "local_kopere_wpbridge"),
            "cohort" => get_string("mapping_itemtype_cohort", "local_kopere_wpbridge"),
        ];
        $mform->addElement(
            "select",
            "itemtype",
            get_string("mapping_itemtype", "local_kopere_wpbridge"),
            $types
        );

        $courses = [0 => "-"];
        foreach (get_courses() as $course) {
            if ($course->id == SITEID) {
                continue;
            }
            $courses[$course->id] = format_string($course->fullname);
        }
        $mform->addElement(
            "select",
            "courseid",
            get_string("mapping_course", "local_kopere_wpbridge"),
            $courses
        );
        $mform->setType("courseid", PARAM_INT);

        global $DB;
        $cohortoptions = [0 => "-"];
        $cohorts = $DB->get_records("cohort", null, "name ASC", "id, name");
        foreach ($cohorts as $cohort) {
            $cohortoptions[$cohort->id] = format_string($cohort->name);
        }
        $mform->addElement(
            "select",
            "cohortid",
            get_string("mapping_cohort", "local_kopere_wpbridge"),
            $cohortoptions
        );
        $mform->setType("cohortid", PARAM_INT);

        $roles = [];
        $rolerecords = $DB->get_records("role", null, "sortorder ASC", "id, shortname");
        foreach ($rolerecords as $role) {
            $roles[$role->id] = format_string($role->shortname);
        }
        $mform->addElement(
            "select",
            "roleid",
            get_string("mapping_role", "local_kopere_wpbridge"),
            $roles
        );
        $mform->setType("roleid", PARAM_INT);

        $mform->addElement(
            "advcheckbox",
            "enabled",
            get_string("mapping_enabled", "local_kopere_wpbridge")
        );
        $mform->setDefault("enabled", 1);

        $mform->hideIf("courseid", "itemtype", "neq", "course");
        $mform->hideIf("roleid", "itemtype", "neq", "course");
        $mform->hideIf("cohortid", "itemtype", "neq", "cohort");

        if ($data) {
            $this->set_data($data);
        }

        $this->add_action_buttons(true, get_string("savechanges"));
    }

    /**
     * Validate the submitted form data.
     *
     * @param array $data Submitted data.
     * @param array $files Uploaded files.
     * @return array
     * @throws coding_exception
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if ($data["itemtype"] == "course" && empty($data["courseid"])) {
            $errors["courseid"] = get_string("mapping_missingcourse", "local_kopere_wpbridge");
        }

        if ($data["itemtype"] == "cohort" && empty($data["cohortid"])) {
            $errors["cohortid"] = get_string("mapping_missingcohort", "local_kopere_wpbridge");
        }

        return $errors;
    }
}
