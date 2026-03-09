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
 * editmapping.php
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require("../../config.php");

use local_kopere_wpbridge\form\mapping_form;
use local_kopere_wpbridge\service\mapping_repository;

require_login();

$context = context_system::instance();
require_capability("local/kopere_wpbridge:manage", $context);

$id = optional_param("id", 0, PARAM_INT);
$courseid = optional_param("courseid", 0, PARAM_INT);

$PAGE->set_url(new moodle_url("/local/kopere_wpbridge/editmapping.php", [
    "id" => $id,
    "courseid" => $courseid,
]));
$PAGE->set_context($context);
$PAGE->set_title(get_string("mapping_edit", "local_kopere_wpbridge"));
$PAGE->set_heading(get_string("pluginname", "local_kopere_wpbridge"));

$repository = new mapping_repository();
$data = $id ? $repository->get($id) : null;

if (!$data) {
    $data = (object) [
        "id" => 0,
        "productid" => 0,
        "itemtype" => "course",
        "courseid" => $courseid,
        "cohortid" => 0,
        "roleid" => 5,
        "enabled" => 1,
    ];
}

$form = new mapping_form(null, ["data" => $data]);

if ($form->is_cancelled()) {
    redirect(new moodle_url("/local/kopere_wpbridge/mappings.php", ["courseid" => $courseid]));
}

if ($fromform = $form->get_data()) {
    $record = (object) [
        "id" => $fromform->id,
        "productid" => $fromform->productid,
        "itemtype" => $fromform->itemtype,
        "courseid" => $fromform->itemtype == "course" ? $fromform->courseid : 0,
        "cohortid" => $fromform->itemtype == "cohort" ? $fromform->cohortid : 0,
        "roleid" => $fromform->roleid,
        "enabled" => empty($fromform->enabled) ? 0 : 1,
    ];

    $repository->save($record);

    redirect(
        new moodle_url("/local/kopere_wpbridge/mappings.php", ["courseid" => $courseid]),
        get_string("mapping_saved", "local_kopere_wpbridge")
    );
}

ob_start();
$form->display();
$formhtml = ob_get_clean();

$templatecontext = [
    "title" => $id ? get_string("mapping_edit", "local_kopere_wpbridge") : get_string("mapping_add", "local_kopere_wpbridge"),
    "form" => $formhtml,
    "backurl" => new moodle_url("/local/kopere_wpbridge/mappings.php", ["courseid" => $courseid]),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template("local_kopere_wpbridge/editmapping", $templatecontext);
echo $OUTPUT->footer();
