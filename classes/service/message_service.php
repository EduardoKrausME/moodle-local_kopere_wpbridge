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
 * message_service.php
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_kopere_wpbridge\service;

use coding_exception;
use core\message\message;
use core_user;
use stdClass;

/**
 * Message sender used for end-user confirmations and admin alerts.
 */
class message_service {
    /**
     * Send a user access notification.
     *
     * @param stdClass $user Moodle user.
     * @param array $items Applied mappings summary.
     * @param string $orderid WooCommerce order ID.
     * @return void
     * @throws coding_exception
     */
    public function send_user_access_email(stdClass $user, array $items, string $orderid): void {
        global $CFG;

        require_once("{$CFG->dirroot}/message/lib.php");

        $supportuser = core_user::get_support_user();

        $a = (object) [
            "firstname" => $user->firstname,
            "orderid" => $orderid,
            "items" => implode("\n", $items),
            "siteurl" => $CFG->wwwroot,
            "sitename" => format_string($CFG->sitename),
        ];

        $message = new message();
        $message->component = "local_kopere_wpbridge";
        $message->name = "syncnotification";
        $message->userfrom = $supportuser;
        $message->userto = $user;
        $message->subject = get_string("ordernotification_subject", "local_kopere_wpbridge");
        $message->fullmessage = get_string("ordernotification_body", "local_kopere_wpbridge", $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = nl2br(format_text($message->fullmessage, FORMAT_PLAIN));
        $message->smallmessage = $message->subject;
        $message->notification = 1;

        message_send($message);
    }

    /**
     * Send an issue notification to all site admins.
     *
     * @param string $messagebody Message body.
     * @return void
     * @throws coding_exception
     */
    public function notify_admin_issue(string $messagebody): void {
        global $CFG;

        require_once("{$CFG->dirroot}/message/lib.php");

        $supportuser = core_user::get_support_user();
        $admins = get_admins();

        foreach ($admins as $admin) {
            $message = new message();
            $message->component = "local_kopere_wpbridge";
            $message->name = "syncnotification";
            $message->userfrom = $supportuser;
            $message->userto = $admin;
            $message->subject = get_string("adminnotification_subject", "local_kopere_wpbridge");
            $message->fullmessage = get_string("adminnotification_body", "local_kopere_wpbridge", $messagebody);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = nl2br(format_text($message->fullmessage, FORMAT_PLAIN));
            $message->smallmessage = $message->subject;
            $message->notification = 1;

            message_send($message);
        }
    }
}
