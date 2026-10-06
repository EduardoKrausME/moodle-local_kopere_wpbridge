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
            "customerid" => (int) ($payload["customer_id"] ?? 0),
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

        $receivedids = [];
        $items = $payload["line_items"] ?? [];
        foreach ($items as $item) {
            $externalitemid = (string) ($item["id"] ?? "");
            if ($externalitemid != "") {
                $receivedids[] = $externalitemid;
            }
            $this->upsert_item($localorderid, $externalid, $item);
        }

        $this->mark_missing_items_removed($localorderid, $receivedids);

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

        $now = time();
        $sql = "SELECT *
                  FROM {local_kopere_wpbridge_item}
                 WHERE orderid = :orderid
                   AND (
                        status = :pending
                        OR (status = :error AND nextretry <= :now)
                   )
              ORDER BY id ASC";

        return $DB->get_records_sql($sql, [
            "orderid" => $orderid,
            "pending" => "pending",
            "error" => "error",
            "now" => $now,
        ]);
    }

    /**
     * Return all local items for an order.
     *
     * @param int $orderid Local order ID.
     * @return array
     * @throws dml_exception
     */
    public function get_items_for_order(int $orderid): array {
        global $DB;
        return $DB->get_records("local_kopere_wpbridge_item", ["orderid" => $orderid], "id ASC");
    }

    /**
     * Return retryable items joined with their orders.
     *
     * @param int $limit Maximum rows.
     * @return array
     * @throws dml_exception
     */
    public function get_pending_items(int $limit = 100): array {
        global $DB;

        $now = time();
        $sql = "SELECT i.*, o.email, o.firstname, o.lastname, o.status AS orderstatus
                  FROM {local_kopere_wpbridge_item} i
                  JOIN {local_kopere_wpbridge_order} o ON o.id = i.orderid
                 WHERE o.status = :completed
                   AND (
                        i.status = :pending
                        OR (i.status = :error AND i.nextretry <= :now)
                   )
              ORDER BY i.id ASC";

        return $DB->get_records_sql($sql, [
            "completed" => "completed",
            "pending" => "pending",
            "error" => "error",
            "now" => $now,
        ], 0, $limit);
    }

    /**
     * Decode stored grants for an item.
     *
     * @param stdClass $item Order item.
     * @return array
     */
    public function get_grants(stdClass $item): array {
        if (empty($item->grants)) {
            return [];
        }

        $grants = json_decode($item->grants, true);
        return is_array($grants) ? $grants : [];
    }

    /**
     * Save grant state for an item.
     *
     * @param int $itemid Item ID.
     * @param array $grants Grant records.
     * @return void
     * @throws dml_exception
     */
    public function save_grants(int $itemid, array $grants): void {
        global $DB;

        $DB->update_record("local_kopere_wpbridge_item", (object) [
            "id" => $itemid,
            "grants" => json_encode($grants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            "timemodified" => time(),
        ]);
    }

    /**
     * Check if another completed order still grants the same target.
     *
     * @param int $userid Moodle user ID.
     * @param int $excludeitemid Item currently being revoked.
     * @param string $itemtype course or cohort.
     * @param int $targetid Target ID.
     * @return bool
     * @throws dml_exception
     */
    public function has_other_active_grant(
        int $userid,
        int $excludeitemid,
        string $itemtype,
        int $targetid
    ): bool {
        global $DB;

        $sql = "SELECT i.*
                  FROM {local_kopere_wpbridge_item} i
                  JOIN {local_kopere_wpbridge_order} o ON o.id = i.orderid
                 WHERE i.userid = :userid
                   AND i.id <> :excludeitemid
                   AND o.status = :completed
                   AND i.status <> :removed
                   AND i.grants IS NOT NULL";

        $items = $DB->get_records_sql($sql, [
            "userid" => $userid,
            "excludeitemid" => $excludeitemid,
            "completed" => "completed",
            "removed" => "removed",
        ]);

        foreach ($items as $item) {
            foreach ($this->get_grants($item) as $grant) {
                if (
                    !empty($grant["active"]) &&
                    ($grant["itemtype"] ?? "") == $itemtype &&
                    (int) ($grant["targetid"] ?? 0) == $targetid
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Transfer ownership of bridge-created access to another active purchase.
     *
     * This is required when order A originally created a manual enrolment, order B later reused it,
     * and order A is refunded. Order B becomes responsible for removing the access if it is later
     * refunded too.
     *
     * @param int $userid Moodle user ID.
     * @param int $excludeitemid Item currently being revoked.
     * @param string $itemtype course or cohort.
     * @param int $targetid Target ID.
     * @return bool
     * @throws dml_exception
     */
    public function transfer_active_grant_ownership(
        int $userid,
        int $excludeitemid,
        string $itemtype,
        int $targetid
    ): bool {
        global $DB;

        $sql = "SELECT i.*
                  FROM {local_kopere_wpbridge_item} i
                  JOIN {local_kopere_wpbridge_order} o ON o.id = i.orderid
                 WHERE i.userid = :userid
                   AND i.id <> :excludeitemid
                   AND o.status = :completed
                   AND i.status <> :removed
                   AND i.grants IS NOT NULL
              ORDER BY i.id ASC";

        $items = $DB->get_records_sql($sql, [
            "userid" => $userid,
            "excludeitemid" => $excludeitemid,
            "completed" => "completed",
            "removed" => "removed",
        ]);

        foreach ($items as $item) {
            $grants = $this->get_grants($item);
            foreach ($grants as $index => $grant) {
                if (
                    !empty($grant["active"]) &&
                    ($grant["itemtype"] ?? "") == $itemtype &&
                    (int) ($grant["targetid"] ?? 0) == $targetid
                ) {
                    $grants[$index]["accesscreated"] = true;
                    $this->save_grants($item->id, $grants);
                    return true;
                }
            }
        }

        return false;
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

        $DB->update_record("local_kopere_wpbridge_item", (object) [
            "id" => $itemid,
            "status" => "processed",
            "userid" => $userid,
            "message" => $message,
            "attempts" => 0,
            "nextretry" => 0,
            "lasterror" => null,
            "timemodified" => time(),
        ]);
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

        $DB->update_record("local_kopere_wpbridge_item", (object) [
            "id" => $itemid,
            "status" => "ignored",
            "message" => $message,
            "timemodified" => time(),
        ]);
    }

    /**
     * Mark an item as revoked.
     *
     * @param int $itemid Local item ID.
     * @param string $message Result message.
     * @return void
     * @throws dml_exception
     */
    public function mark_revoked(int $itemid, string $message): void {
        global $DB;

        $DB->update_record("local_kopere_wpbridge_item", (object) [
            "id" => $itemid,
            "status" => "revoked",
            "message" => $message,
            "timemodified" => time(),
        ]);
    }

    /**
     * Mark an item as error and schedule an exponential-backoff retry.
     *
     * @param int $itemid Local item ID.
     * @param string $message Error message.
     * @return void
     * @throws dml_exception
     */
    public function mark_error(int $itemid, string $message): void {
        global $DB;

        $item = $DB->get_record("local_kopere_wpbridge_item", ["id" => $itemid], "id, attempts", MUST_EXIST);
        $attempts = ((int) $item->attempts) + 1;
        $exponent = min($attempts - 1, 8);
        $delay = min(21600, 60 * (2 ** $exponent));

        $DB->update_record("local_kopere_wpbridge_item", (object) [
            "id" => $itemid,
            "status" => "error",
            "message" => $message,
            "attempts" => $attempts,
            "nextretry" => time() + $delay,
            "lasterror" => $message,
            "timemodified" => time(),
        ]);
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
            $record->grants = $existing->grants;

            if ($existing->status == "processed") {
                $record->status = $existing->status;
                $record->userid = $existing->userid;
                $record->message = $existing->message;
                $record->attempts = $existing->attempts;
                $record->nextretry = $existing->nextretry;
                $record->lasterror = $existing->lasterror;
            } else {
                $record->status = "pending";
                $record->userid = $existing->userid;
                $record->message = "";
                $record->attempts = 0;
                $record->nextretry = 0;
                $record->lasterror = null;
            }

            $DB->update_record("local_kopere_wpbridge_item", $record);
            return;
        }

        $record->status = "pending";
        $record->userid = 0;
        $record->message = "";
        $record->attempts = 0;
        $record->nextretry = 0;
        $record->lasterror = null;
        $record->grants = null;
        $record->timecreated = $now;
        $DB->insert_record("local_kopere_wpbridge_item", $record);
    }

    /**
     * Mark order items that no longer exist in the WooCommerce payload as removed.
     *
     * @param int $orderid Local order ID.
     * @param array $receivedids Current WooCommerce line item IDs.
     * @return void
     * @throws dml_exception
     */
    protected function mark_missing_items_removed(int $orderid, array $receivedids): void {
        global $DB;

        $items = $DB->get_records("local_kopere_wpbridge_item", ["orderid" => $orderid]);
        foreach ($items as $item) {
            if (in_array((string) $item->externalitemid, $receivedids, true)) {
                continue;
            }

            if ($item->status == "removed") {
                continue;
            }

            $DB->update_record("local_kopere_wpbridge_item", (object) [
                "id" => $item->id,
                "status" => "removed",
                "message" => "WooCommerce line item removed from order.",
                "timemodified" => time(),
            ]);
        }
    }
}
