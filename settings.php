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
 * settings.php
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once("{$CFG->dirroot}/local/kopere_wpbridge/lib.php");

use local_kopere_wpbridge\service\webhook_manager;

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        "local_kopere_wpbridge",
        get_string("pluginname", "local_kopere_wpbridge")
    );

    $token = webhook_manager::ensure_token();
    $webhookurl = new moodle_url("/local/kopere_wpbridge/webhooks.php", [
        "token" => $token,
    ]);

    $settings->add(
        new admin_setting_heading(
            "local_kopere_wpbridge/webhookheading",
            get_string("settings_webhookheading", "local_kopere_wpbridge"),
            get_string("settings_webhookheading_desc", "local_kopere_wpbridge") .
            "<br><br><code>" . $webhookurl . "</code>"
        )
    );

    $connectionmessage = get_config("local_kopere_wpbridge", "connectionmessage");
    if ($connectionmessage == "") {
        $connectionmessage = get_string("settings_notconfigured", "local_kopere_wpbridge");
    }

    $settings->add(
        new admin_setting_heading(
            "local_kopere_wpbridge/statusheading",
            get_string("settings_statusheading", "local_kopere_wpbridge"),
            format_text($connectionmessage, FORMAT_HTML)
        )
    );

    $storeurl = new admin_setting_configtext(
        "local_kopere_wpbridge/storeurl",
        get_string("settings_storeurl", "local_kopere_wpbridge"),
        get_string("settings_storeurl_desc", "local_kopere_wpbridge"),
        "",
        PARAM_URL
    );
    $storeurl->set_updatedcallback("local_kopere_wpbridge_after_settings_save");
    $settings->add($storeurl);

    $consumerkey = new admin_setting_heading(
        "local_kopere_wpbridge/webhookurl",
        get_string("settings_webhookurl", "local_kopere_wpbridge"),
        webhook_manager::get_webhook_url()
    );

    $consumerkey = new admin_setting_configtext(
        "local_kopere_wpbridge/consumerkey",
        get_string("settings_consumerkey", "local_kopere_wpbridge"),
        "",
        "",
        PARAM_TEXT
    );
    $consumerkey->set_updatedcallback("local_kopere_wpbridge_after_settings_save");
    $settings->add($consumerkey);

    $consumersecret = new admin_setting_configtext(
        "local_kopere_wpbridge/consumersecret",
        get_string("settings_consumersecret", "local_kopere_wpbridge"),
        "",
        ""
    );
    $consumersecret->set_updatedcallback("local_kopere_wpbridge_after_settings_save");
    $settings->add($consumersecret);

    $debug = new admin_setting_configcheckbox(
        "local_kopere_wpbridge/debug",
        get_string("settings_debug", "local_kopere_wpbridge"),
        "",
        0
    );
    $debug->set_updatedcallback("local_kopere_wpbridge_after_settings_save");
    $settings->add($debug);

    $ADMIN->add("localplugins", $settings);
}
