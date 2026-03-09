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
 * mappings.php
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require("../../config.php");
require_once("{$CFG->libdir}/tablelib.php");

use local_kopere_wpbridge\service\mapping_repository;

require_login();

$context = context_system::instance();
require_capability("local/kopere_wpbridge:manage", $context);

$courseid = optional_param("courseid", 0, PARAM_INT);
$deleteid = optional_param("delete", 0, PARAM_INT);
$confirm = optional_param("confirm", 0, PARAM_BOOL);

$PAGE->set_url(new moodle_url("/local/kopere_wpbridge/mappings.php", ["courseid" => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string("mappings", "local_kopere_wpbridge"));
$PAGE->set_heading(get_string("pluginname", "local_kopere_wpbridge"));

$repository = new mapping_repository();

if ($deleteid) {
    $backurl = new moodle_url("/local/kopere_wpbridge/mappings.php", ["courseid" => $courseid]);

    if ($confirm && confirm_sesskey()) {
        $repository->delete($deleteid);
        redirect(
            $backurl,
            get_string("mapping_deleted", "local_kopere_wpbridge")
        );
    }

    echo $OUTPUT->header();

    $confirmurl = new moodle_url("/local/kopere_wpbridge/mappings.php", [
        "delete" => $deleteid,
        "courseid" => $courseid,
        "confirm" => 1,
        "sesskey" => sesskey(),
    ]);

    echo $OUTPUT->confirm(
        get_string("mapping_delete_confirm", "local_kopere_wpbridge"),
        $confirmurl,
        $backurl
    );

    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();

$templatecontext = [
    "title" => get_string("mappings", "local_kopere_wpbridge"),
    "addurl" => new moodle_url("/local/kopere_wpbridge/editmapping.php", ["courseid" => $courseid]),
    "backurl" => new moodle_url("/local/kopere_wpbridge/"),
];
echo $OUTPUT->render_from_template("local_kopere_wpbridge/mappings", $templatecontext);

$table = new flexible_table("local-kopere-wpbridge-mappings");
$table->define_baseurl(new moodle_url("/local/kopere_wpbridge/mappings.php", ["courseid" => $courseid]));
$table->define_columns(["productid", "itemtype", "destination", "enabled", "actions"]);
$table->define_headers([
    get_string("mapping_productid", "local_kopere_wpbridge"),
    get_string("mapping_itemtype", "local_kopere_wpbridge"),
    get_string("mappings", "local_kopere_wpbridge"),
    get_string("mapping_enabled", "local_kopere_wpbridge"),
    get_string("actions", "local_kopere_wpbridge"),
]);
$table->sortable(false);
$table->collapsible(false);
$table->set_attribute("class", "generaltable table table-striped");
$table->setup();

$records = $repository->get_all($courseid ?: null);
foreach ($records as $record) {
    $destination = $record->itemtype == "course" ? $record->coursename : $record->cohortname;

    $editurl = new moodle_url("/local/kopere_wpbridge/editmapping.php", [
        "id" => $record->id,
        "courseid" => $courseid,
    ]);

    $deleteurl = new moodle_url("/local/kopere_wpbridge/mappings.php", [
        "delete" => $record->id,
        "courseid" => $courseid,
    ]);

    $actions = html_writer::link($editurl, get_string("mapping_edit", "local_kopere_wpbridge"), [
        "class" => "btn btn-sm btn-primary me-2",
    ]);
    $actions .= html_writer::link($deleteurl, get_string("mapping_delete", "local_kopere_wpbridge"), [
        "class" => "btn btn-sm btn-danger",
    ]);

    $table->add_data([
        $record->productid,
        $record->itemtype,
        $destination,
        $record->enabled ? get_string("yes") : get_string("no"),
        $actions,
    ]);
}

$table->finish_output();

echo $OUTPUT->footer();
