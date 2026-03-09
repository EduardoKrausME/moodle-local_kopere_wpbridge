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
 * index.php
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_kopere_wpbridge\service\webhook_manager;

require("../../config.php");

require_login();

$context = context_system::instance();
require_capability("local/kopere_wpbridge:view", $context);

$PAGE->set_url(new moodle_url("/local/kopere_wpbridge/"));
$PAGE->set_context($context);
$PAGE->set_title(get_string("dashboard_title", "local_kopere_wpbridge"));
$PAGE->set_heading(get_string("pluginname", "local_kopere_wpbridge"));

$mappingcount = $DB->count_records("local_kopere_wpbridge_map");
$pendingcount = $DB->count_records("local_kopere_wpbridge_item", ["status" => "pending"]);
$processedcount = $DB->count_records("local_kopere_wpbridge_item", ["status" => "processed"]);
$connectionmessage = get_config("local_kopere_wpbridge", "connectionmessage");
if ($connectionmessage == "") {
    $connectionmessage = get_string("settings_notconfigured", "local_kopere_wpbridge");
}

$templatecontext = [
    "title" => get_string("dashboard_title", "local_kopere_wpbridge"),
    "subtitle" => get_string("dashboard_subtitle", "local_kopere_wpbridge"),
    "mappingcount" => $mappingcount,
    "pendingcount" => $pendingcount,
    "processedcount" => $processedcount,
    "connectionmessage" => $connectionmessage,
    "mappingsurl" => new moodle_url("/local/kopere_wpbridge/mappings.php"),
    "settingsurl" => new moodle_url("/admin/settings.php", ["section" => "local_kopere_wpbridge"]),
    "canmanage" => has_capability("local/kopere_wpbridge:manage", $context),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template("local_kopere_wpbridge/index", $templatecontext);
echo $OUTPUT->footer();
