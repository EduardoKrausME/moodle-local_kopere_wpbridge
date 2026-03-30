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
 * Privacy provider.
 *
 * @package    local_kopere_wpbridge
 * @copyright  2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_kopere_wpbridge\privacy;

use context;
use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as plugin_provider;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use stdClass;

/**
 * phpcs:disable Universal.OOStructures.AlphabeticExtendsImplements.ImplementsWrongOrder
 * Privacy API provider for local_kopere_wpbridge.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    plugin_provider,
    core_userlist_provider {

    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection The collection to add metadata to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            "local_kopere_wpbridge_order",
            [
                "externalid" => "privacy:metadata:local_kopere_wpbridge_order:externalid",
                "status" => "privacy:metadata:local_kopere_wpbridge_order:status",
                "source" => "privacy:metadata:local_kopere_wpbridge_order:source",
                "email" => "privacy:metadata:local_kopere_wpbridge_order:email",
                "firstname" => "privacy:metadata:local_kopere_wpbridge_order:firstname",
                "lastname" => "privacy:metadata:local_kopere_wpbridge_order:lastname",
                "currency" => "privacy:metadata:local_kopere_wpbridge_order:currency",
                "total" => "privacy:metadata:local_kopere_wpbridge_order:total",
                "payload" => "privacy:metadata:local_kopere_wpbridge_order:payload",
                "timecreated" => "privacy:metadata:local_kopere_wpbridge_order:timecreated",
                "timemodified" => "privacy:metadata:local_kopere_wpbridge_order:timemodified",
            ],
            "privacy:metadata:local_kopere_wpbridge_order"
        );

        $collection->add_database_table(
            "local_kopere_wpbridge_item",
            [
                "orderid" => "privacy:metadata:local_kopere_wpbridge_item:orderid",
                "externalitemid" => "privacy:metadata:local_kopere_wpbridge_item:externalitemid",
                "externalorderid" => "privacy:metadata:local_kopere_wpbridge_item:externalorderid",
                "productid" => "privacy:metadata:local_kopere_wpbridge_item:productid",
                "productname" => "privacy:metadata:local_kopere_wpbridge_item:productname",
                "quantity" => "privacy:metadata:local_kopere_wpbridge_item:quantity",
                "status" => "privacy:metadata:local_kopere_wpbridge_item:status",
                "userid" => "privacy:metadata:local_kopere_wpbridge_item:userid",
                "message" => "privacy:metadata:local_kopere_wpbridge_item:message",
                "payload" => "privacy:metadata:local_kopere_wpbridge_item:payload",
                "timecreated" => "privacy:metadata:local_kopere_wpbridge_item:timecreated",
                "timemodified" => "privacy:metadata:local_kopere_wpbridge_item:timemodified",
            ],
            "privacy:metadata:local_kopere_wpbridge_item"
        );

        $collection->add_subsystem_link(
            "core_message",
            [],
            "privacy:metadata:core_message"
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        if (self::user_has_data($userid)) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * Export all user data for the specified user.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        if (!$contextlist->count()) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        $orders = self::get_orders_for_user($userid);
        if (!$orders) {
            return;
        }

        $items = self::get_items_for_user($userid);
        $itemsbyorder = [];

        foreach ($items as $item) {
            if (!isset($itemsbyorder[$item->orderid])) {
                $itemsbyorder[$item->orderid] = [];
            }
            $itemsbyorder[$item->orderid][] = self::prepare_item_for_export($item);
        }

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_SYSTEM) {
                continue;
            }

            foreach ($orders as $order) {
                $export = (object) [
                    "order" => self::prepare_order_for_export($order),
                    "items" => array_values($itemsbyorder[$order->id] ?? []),
                ];

                writer::with_context($context)->export_data(
                    [
                        get_string("privacy:path:orders", "local_kopere_wpbridge"),
                        (string) $order->externalid,
                    ],
                    $export
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context The specific context to delete data for.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        $DB->delete_records("local_kopere_wpbridge_item");
        $DB->delete_records("local_kopere_wpbridge_order");
    }

    /**
     * Delete all user data for the specified user, in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (!$contextlist->count()) {
            return;
        }

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_SYSTEM) {
                continue;
            }

            self::delete_user_data($contextlist->get_user()->id);
        }
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof context_system) {
            return;
        }

        $userlist->add_from_sql(
            "userid",
            "SELECT DISTINCT userid
               FROM {local_kopere_wpbridge_item}
              WHERE userid > 0",
            []
        );

        $sql = "SELECT DISTINCT u.id
                  FROM {local_kopere_wpbridge_order} o
                  JOIN {user} u
                    ON " . $DB->sql_compare_text("u.email") . " = " . $DB->sql_compare_text("o.email") . "
                 WHERE u.deleted = 0
                   AND o.email IS NOT NULL
                   AND o.email <> ''";
        $userlist->add_from_sql("id", $sql, []);
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_system) {
            return;
        }

        foreach ($userlist->get_userids() as $userid) {
            self::delete_user_data((int) $userid);
        }
    }

    /**
     * Check whether the user has data in this plugin.
     *
     * @param int $userid The user ID.
     * @return bool
     */
    protected static function user_has_data(int $userid): bool {
        global $DB;

        $user = $DB->get_record("user", ["id" => $userid], "id, email");
        if (!$user) {
            return $DB->record_exists("local_kopere_wpbridge_item", ["userid" => $userid]);
        }

        $sql = "SELECT 1
                  FROM {local_kopere_wpbridge_order} o
             LEFT JOIN {local_kopere_wpbridge_item} i
                    ON i.orderid = o.id
                 WHERE i.userid = :userid
                    OR " . $DB->sql_compare_text("o.email") . " = " . $DB->sql_compare_text(":email");

        return $DB->record_exists_sql($sql, [
            "userid" => $userid,
            "email" => $user->email,
        ]);
    }

    /**
     * Return all mirrored orders related to the specified user.
     *
     * @param int $userid The user ID.
     * @return array
     */
    protected static function get_orders_for_user(int $userid): array {
        global $DB;

        $user = $DB->get_record("user", ["id" => $userid], "id, email");
        if (!$user) {
            return [];
        }

        $sql = "SELECT DISTINCT o.*
                  FROM {local_kopere_wpbridge_order} o
             LEFT JOIN {local_kopere_wpbridge_item} i
                    ON i.orderid = o.id
                 WHERE i.userid = :userid
                    OR " . $DB->sql_compare_text("o.email") . " = " . $DB->sql_compare_text(":email") . "
              ORDER BY o.timecreated ASC, o.id ASC";

        return $DB->get_records_sql($sql, [
            "userid" => $userid,
            "email" => $user->email,
        ]);
    }

    /**
     * Return all mirrored items related to the specified user.
     *
     * @param int $userid The user ID.
     * @return array
     */
    protected static function get_items_for_user(int $userid): array {
        global $DB;

        $user = $DB->get_record("user", ["id" => $userid], "id, email");
        if (!$user) {
            return [];
        }

        $sql = "SELECT i.*
                  FROM {local_kopere_wpbridge_item} i
                  JOIN {local_kopere_wpbridge_order} o
                    ON o.id = i.orderid
                 WHERE i.userid = :userid
                    OR " . $DB->sql_compare_text("o.email") . " = " . $DB->sql_compare_text(":email") . "
              ORDER BY i.timecreated ASC, i.id ASC";

        return $DB->get_records_sql($sql, [
            "userid" => $userid,
            "email" => $user->email,
        ]);
    }

    /**
     * Delete all plugin data related to one user.
     *
     * @param int $userid The user ID.
     * @return void
     */
    protected static function delete_user_data(int $userid): void {
        global $DB;

        $orderids = self::get_order_ids_for_user($userid);

        if ($orderids) {
            [$insql, $params] = $DB->get_in_or_equal($orderids, SQL_PARAMS_NAMED);

            $DB->delete_records_select(
                "local_kopere_wpbridge_item",
                "orderid {$insql}",
                $params
            );

            $DB->delete_records_select(
                "local_kopere_wpbridge_order",
                "id {$insql}",
                $params
            );
        }

        $DB->delete_records("local_kopere_wpbridge_item", ["userid" => $userid]);
    }

    /**
     * Return the local order IDs related to one user.
     *
     * @param int $userid The user ID.
     * @return array
     */
    protected static function get_order_ids_for_user(int $userid): array {
        global $DB;

        $user = $DB->get_record("user", ["id" => $userid], "id, email");

        if ($user) {
            $sql = "SELECT DISTINCT o.id
                      FROM {local_kopere_wpbridge_order} o
                 LEFT JOIN {local_kopere_wpbridge_item} i
                        ON i.orderid = o.id
                     WHERE i.userid = :userid
                        OR " . $DB->sql_compare_text("o.email") . " = " . $DB->sql_compare_text(":email");

            return $DB->get_fieldset_sql($sql, [
                "userid" => $userid,
                "email" => $user->email,
            ]);
        }

        return $DB->get_fieldset_select(
            "local_kopere_wpbridge_item",
            "orderid",
            "userid = :userid",
            ["userid" => $userid]
        );
    }

    /**
     * Prepare an order record for export.
     *
     * @param stdClass $order The order record.
     * @return stdClass
     */
    protected static function prepare_order_for_export(stdClass $order): stdClass {
        return (object) [
            "id" => $order->id,
            "externalid" => $order->externalid,
            "status" => $order->status,
            "source" => $order->source,
            "email" => $order->email,
            "firstname" => $order->firstname,
            "lastname" => $order->lastname,
            "currency" => $order->currency,
            "total" => $order->total,
            "payload" => $order->payload,
            "timecreated" => $order->timecreated,
            "timemodified" => $order->timemodified,
        ];
    }

    /**
     * Prepare an item record for export.
     *
     * @param stdClass $item The item record.
     * @return stdClass
     */
    protected static function prepare_item_for_export(stdClass $item): stdClass {
        return (object) [
            "id" => $item->id,
            "orderid" => $item->orderid,
            "externalitemid" => $item->externalitemid,
            "externalorderid" => $item->externalorderid,
            "productid" => $item->productid,
            "productname" => $item->productname,
            "quantity" => $item->quantity,
            "status" => $item->status,
            "userid" => $item->userid,
            "message" => $item->message,
            "payload" => $item->payload,
            "timecreated" => $item->timecreated,
            "timemodified" => $item->timemodified,
        ];
    }
}
