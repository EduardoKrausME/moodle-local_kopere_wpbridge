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
 * order_repository.php
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_kopere_wpbridge\service;

use dml_exception;
use moodle_exception;
use stdClass;

/**
 * Repository for WooCommerce mirrored orders and items.
 */
class order_repository {
    /**
     * Create or update an order and all its items from the WooCommerce payload.
     *
     * @param array $payload WooCommerce order payload.
     * @param string $source Source name.
     * @return stdClass
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function upsert_from_payload(array $payload, string $source): stdClass {
        global $DB;

        $externalid = $payload["id"] ?? "";
        if ($externalid == "") {
            throw new moodle_exception("error_missingorderid", "local_kopere_wpbridge");
        }

        $billing = $payload["billing"] ?? [];
        $existing = $DB->get_record("local_kopere_wpbridge_order", ["externalid" => $externalid]);
        $now = time();

        $record = (object) [
            "externalid" => $externalid,
            "status" => $payload["status"] ?? "pending",
            "source" => $source,
            "email" => trim($billing["email"] ?? ""),
            "firstname" => trim($billing["first_name"] ?? ""),
            "lastname" => trim($billing["last_name"] ?? ""),
            "currency" => trim($payload["currency"] ?? ""),
            "total" => trim($payload["total"] ?? ""),
            "payload" => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            "timemodified" => $now,
        ];

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record("local_kopere_wpbridge_order", $record);
            $localorderid = $existing->id;
        } else {
            $record->timecreated = $now;
            $localorderid = $DB->insert_record("local_kopere_wpbridge_order", $record);
        }

        $items = $payload["line_items"] ?? [];
        foreach ($items as $item) {
            $this->upsert_item($localorderid, $externalid, $item);
        }

        return $DB->get_record("local_kopere_wpbridge_order", ["id" => $localorderid], "*", MUST_EXIST);
    }

    /**
     * Return a mirrored order by external ID.
     *
     * @param string $externalid WooCommerce order ID.
     * @return stdClass|null
     * @throws dml_exception
     */
    public function get_order_by_externalid(string $externalid): ?stdClass {
        global $DB;

        $record = $DB->get_record("local_kopere_wpbridge_order", ["externalid" => $externalid]);
        return $record ?: null;
    }

    /**
     * Return processable items for a specific local order.
     *
     * @param int $orderid Local order ID.
     * @return array
     * @throws dml_exception
     */
    public function get_open_items_for_order(int $orderid): array {
        global $DB;

        $sql = "SELECT *
                  FROM {local_kopere_wpbridge_item}
                 WHERE orderid = :orderid
                   AND status IN (:pending, :error)
              ORDER BY id ASC";

        return $DB->get_records_sql($sql, [
            "orderid" => $orderid,
            "pending" => "pending",
            "error" => "error",
        ]);
    }

    /**
     * Return pending items joined with their orders.
     *
     * @param int $limit Maximum rows.
     * @return array
     * @throws dml_exception
     */
    public function get_pending_items(int $limit = 100): array {
        global $DB;

        $sql = "SELECT i.*, o.email, o.firstname, o.lastname, o.status AS orderstatus
                  FROM {local_kopere_wpbridge_item} i
                  JOIN {local_kopere_wpbridge_order} o
                    ON o.id = i.orderid
                 WHERE o.status = :completed
                   AND i.status = :pending
              ORDER BY i.id ASC";

        return $DB->get_records_sql($sql, [
            "completed" => "completed",
            "pending" => "pending",
        ], 0, $limit);
    }

    /**
     * Mark an item as processed.
     *
     * @param int $itemid Local item ID.
     * @param int $userid Moodle user ID.
     * @param string $message Result message.
     * @return void
     * @throws dml_exception
     */
    public function mark_processed(int $itemid, int $userid, string $message): void {
        global $DB;

        $record = (object) [
            "id" => $itemid,
            "status" => "processed",
            "userid" => $userid,
            "message" => $message,
            "timemodified" => time(),
        ];

        $DB->update_record("local_kopere_wpbridge_item", $record);
    }

    /**
     * Mark an item as ignored.
     *
     * @param int $itemid Local item ID.
     * @param string $message Result message.
     * @return void
     * @throws dml_exception
     */
    public function mark_ignored(int $itemid, string $message): void {
        global $DB;

        $record = (object) [
            "id" => $itemid,
            "status" => "ignored",
            "message" => $message,
            "timemodified" => time(),
        ];

        $DB->update_record("local_kopere_wpbridge_item", $record);
    }

    /**
     * Mark an item as error.
     *
     * @param int $itemid Local item ID.
     * @param string $message Error message.
     * @return void
     * @throws dml_exception
     */
    public function mark_error(int $itemid, string $message): void {
        global $DB;

        $record = (object) [
            "id" => $itemid,
            "status" => "error",
            "message" => $message,
            "timemodified" => time(),
        ];

        $DB->update_record("local_kopere_wpbridge_item", $record);
    }

    /**
     * Insert or update a mirrored order item.
     *
     * @param int $localorderid Local order ID.
     * @param string $externalorderid WooCommerce order ID.
     * @param array $item WooCommerce line item.
     * @return void
     * @throws dml_exception
     */
    protected function upsert_item(int $localorderid, string $externalorderid, array $item): void {
        global $DB;

        $externalitemid = ($item["id"] ?? "");
        if ($externalitemid == "") {
            return;
        }

        $existing = $DB->get_record("local_kopere_wpbridge_item", [
            "externalitemid" => $externalitemid,
        ]);

        $now = time();
        $record = (object) [
            "orderid" => $localorderid,
            "externalitemid" => $externalitemid,
            "externalorderid" => $externalorderid,
            "productid" => $item["product_id"] ?? 0,
            "productname" => trim($item["name"] ?? ""),
            "quantity" => max(1, ($item["quantity"] ?? 1)),
            "payload" => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            "timemodified" => $now,
        ];

        if ($existing) {
            $record->id = $existing->id;
            if ($existing->status == "processed") {
                $record->status = "processed";
                $record->userid = $existing->userid;
                $record->message = $existing->message;
            } else {
                $record->status = "pending";
                $record->userid = 0;
                $record->message = "";
            }

            $DB->update_record("local_kopere_wpbridge_item", $record);
            return;
        }

        $record->status = "pending";
        $record->userid = 0;
        $record->message = "";
        $record->timecreated = $now;
        $DB->insert_record("local_kopere_wpbridge_item", $record);
    }
}
