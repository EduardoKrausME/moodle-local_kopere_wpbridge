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
 * order_sync_service.php
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_kopere_wpbridge\service;

use coding_exception;
use core\lock\lock_config;
use dml_exception;
use local_kopere_wpbridge\api\woocommerce_client;
use moodle_exception;
use Random\RandomException;
use stdClass;
use Throwable;

/**
 * Main service responsible for webhook ingestion, polling and Moodle processing.
 */
class order_sync_service {
    /** @var order_repository */
    protected order_repository $orders;

    /** @var mapping_repository */
    protected mapping_repository $mappings;

    /** @var enrollment_service */
    protected enrollment_service $enrolment;

    /** @var message_service */
    protected message_service $messages;

    /**
     * Service constructor.
     */
    public function __construct() {
        $this->orders = new order_repository();
        $this->mappings = new mapping_repository();
        $this->enrolment = new enrollment_service();
        $this->messages = new message_service();
    }

    /**
     * Handle a WooCommerce webhook payload.
     *
     * @param string $rawbody Raw request body.
     * @param array $server Server variables.
     * @return array
     * @throws dml_exception
     * @throws moodle_exception
     * @throws RandomException
     */
    public function handle_webhook_payload(string $rawbody, array $server = []): array {
        $payload = json_decode($rawbody, true);
        if (!is_array($payload)) {
            throw new moodle_exception("error_missingorderid", "local_kopere_wpbridge", "", null, "Invalid JSON payload.");
        }

        $order = $this->orders->upsert_from_payload($payload, "webhook");
        return $this->process_order_state($order);
    }

    /**
     * Poll every WooCommerce order modified since the last successful cursor.
     *
     * A five-minute overlap protects against clock skew and boundary conditions. Unlike the previous
     * fixed 250-order window, pagination continues until all modified orders have been consumed.
     *
     * @return void
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function sync_recent_completed_orders(): void {
        $client = new woocommerce_client();
        $perpage = 50;
        $cursor = get_config("local_kopere_wpbridge", "syncmodifiedcursor");

        if (!$cursor) {
            $cursor = gmdate("Y-m-d\TH:i:s", time() - (30 * DAYSECS));
        }

        $aftertimestamp = strtotime($cursor);
        if ($aftertimestamp === false) {
            $aftertimestamp = time() - (30 * DAYSECS);
        }

        $after = gmdate("Y-m-d\TH:i:s", max(0, $aftertimestamp - 300));
        $maxmodified = $cursor;
        $page = 1;

        while (true) {
            $payloads = $client->get_modified_orders($after, $page, $perpage);
            if (!$payloads) {
                break;
            }

            foreach ($payloads as $payload) {
                $order = $this->orders->upsert_from_payload($payload, "task");
                $this->process_order_state($order);

                $modified = $payload["date_modified_gmt"] ?? "";
                if ($modified != "") {
                    $candidate = strtotime($modified . " UTC");
                    $current = strtotime($maxmodified);
                    if ($candidate !== false && ($current === false || $candidate > $current)) {
                        $maxmodified = gmdate("Y-m-d\TH:i:s", $candidate);
                    }
                }
            }

            if (count($payloads) < $perpage) {
                break;
            }

            $page++;
        }

        if ($maxmodified == $cursor) {
            $maxmodified = gmdate("Y-m-d\TH:i:s", time());
        }
        set_config("syncmodifiedcursor", $maxmodified, "local_kopere_wpbridge");

        $this->process_pending_items();
    }

    /**
     * Process pending/error items that may already be stored locally.
     *
     * @param int $limit Maximum items.
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     */
    public function process_pending_items(int $limit = 100): void {
        $items = $this->orders->get_pending_items($limit);
        $seenorders = [];

        foreach ($items as $item) {
            if (isset($seenorders[$item->orderid])) {
                continue;
            }
            $seenorders[$item->orderid] = true;

            try {
                $order = $this->orders->get_order_by_externalid($item->externalorderid);
                if (!$order) {
                    continue;
                }

                $this->process_order_state($order);
            } catch (Throwable $exception) {
                $this->orders->mark_error($item->id, $exception->getMessage());
                $this->messages->notify_admin_issue($exception->getMessage());
            }
        }
    }

    /**
     * Process the current state of an order while holding an order-level lock.
     *
     * @param stdClass $order Mirrored order.
     * @return array
     * @throws moodle_exception
     */
    protected function process_order_state(stdClass $order): array {
        $factory = lock_config::get_lock_factory("local_kopere_wpbridge");
        $lock = $factory->get_lock("order_" . $order->externalid, 15);

        if (!$lock) {
            throw new moodle_exception("error_locktimeout", "local_kopere_wpbridge");
        }

        try {
            if ($order->status == "completed") {
                $result = $this->process_order($order);
                $this->revoke_removed_items($order);
                return $result;
            }

            if (in_array($order->status, ["cancelled", "refunded", "failed", "trash"], true)) {
                return $this->revoke_order($order);
            }

            return [
                "saved" => true,
                "processed" => false,
                "status" => $order->status,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Process all retryable items of a completed mirrored order.
     *
     * @param stdClass $order Mirrored order.
     * @return array
     * @throws RandomException
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    protected function process_order(stdClass $order): array {
        $items = $this->orders->get_open_items_for_order($order->id);
        if (!$items) {
            return [
                "saved" => true,
                "processed" => false,
                "status" => "nothing-to-do",
            ];
        }

        $user = $this->enrolment->ensure_user_from_order($order);
        $notifications = [];
        $results = [];

        foreach ($items as $item) {
            try {
                $mappings = $this->mappings->get_active_by_product($item->productid);
                if (!$mappings) {
                    $this->orders->mark_ignored(
                        $item->id,
                        get_string("error_nomapping", "local_kopere_wpbridge")
                    );
                    continue;
                }

                $grants = $this->orders->get_grants($item);
                $messages = [];

                foreach ($mappings as $mapping) {
                    $signature = $this->mapping_signature($mapping);
                    $existingindex = $this->find_grant_index($grants, $signature);

                    if ($existingindex !== null && !empty($grants[$existingindex]["active"])) {
                        continue;
                    }

                    $applied = $this->enrolment->apply_mapping($user->id, $mapping);
                    $grant = [
                        "signature" => $signature,
                        "mappingid" => (int) $mapping->id,
                        "itemtype" => $applied["itemtype"],
                        "targetid" => (int) $applied["targetid"],
                        "roleid" => (int) $applied["roleid"],
                        "accesscreated" => !empty($applied["accesscreated"]),
                        "active" => true,
                        "timegranted" => time(),
                        "timerevoked" => 0,
                    ];

                    if ($existingindex === null) {
                        $grants[] = $grant;
                    } else {
                        $grants[$existingindex] = $grant;
                    }

                    $messages[] = $applied["message"];
                    if (!empty($applied["accesscreated"])) {
                        $notifications[] = $item->productname . " => " . $applied["message"];
                    }
                }

                $this->orders->save_grants($item->id, $grants);

                if ($messages) {
                    $finalmessage = implode("; ", $messages);
                } else {
                    $finalmessage = "Mappings already reconciled.";
                }

                $this->orders->mark_processed($item->id, $user->id, $finalmessage);
                $results[] = $item->productname . " => " . $finalmessage;
            } catch (Throwable $exception) {
                $this->orders->mark_error($item->id, $exception->getMessage());
                $this->messages->notify_admin_issue($exception->getMessage());
            }
        }

        if ($notifications) {
            $this->messages->send_user_access_email($user, $notifications, $order->externalid);
        }

        return [
            "saved" => true,
            "processed" => !empty($results),
            "items" => $results,
        ];
    }

    /**
     * Revoke bridge-created access for cancelled/refunded/failed orders.
     *
     * @param stdClass $order Mirrored order.
     * @return array
     * @throws dml_exception
     */
    protected function revoke_order(stdClass $order): array {
        $items = $this->orders->get_items_for_order($order->id);
        $revoked = [];

        foreach ($items as $item) {
            $count = $this->revoke_item_grants($item);
            if ($count > 0 || $item->status == "processed") {
                $this->orders->mark_revoked(
                    $item->id,
                    "Order status {$order->status}; bridge-created access reconciled."
                );
            }
            $revoked += $count;
        }

        return [
            "saved" => true,
            "processed" => false,
            "revoked" => $revoked,
            "status" => $order->status,
        ];
    }

    /**
     * Revoke grants belonging to removed line items while the order itself remains completed.
     *
     * @param stdClass $order Mirrored order.
     * @return void
     * @throws dml_exception
     */
    protected function revoke_removed_items(stdClass $order): void {
        foreach ($this->orders->get_items_for_order($order->id) as $item) {
            if ($item->status != "removed") {
                continue;
            }

            $this->revoke_item_grants($item);
        }
    }

    /**
     * Revoke all active grants of one item when safe.
     *
     * Mapping removal does not call this method: mappings are intentionally additive and never
     * remove an enrolment. This method is reserved for order/item state changes from WooCommerce.
     *
     * @param stdClass $item Order item.
     * @return int Number of access records physically removed.
     * @throws dml_exception
     */
    protected function revoke_item_grants(stdClass $item): int {
        $grants = $this->orders->get_grants($item);
        if (!$grants) {
            return 0;
        }

        $revoked = 0;
        foreach ($grants as $index => $grant) {
            if (empty($grant["active"])) {
                continue;
            }

            $itemtype = (string) ($grant["itemtype"] ?? "");
            $targetid = (int) ($grant["targetid"] ?? 0);
            $hasother = $this->orders->has_other_active_grant(
                (int) $item->userid,
                (int) $item->id,
                $itemtype,
                $targetid
            );

            if (!$hasother && $this->enrolment->revoke_grant((int) $item->userid, $grant)) {
                $revoked++;
            }

            $grants[$index]["active"] = false;
            $grants[$index]["timerevoked"] = time();
        }

        $this->orders->save_grants($item->id, $grants);
        return $revoked;
    }

    /**
     * Build a stable signature for a mapping version.
     *
     * Changing a mapping produces a new signature and therefore grants the new destination,
     * while the old access remains untouched as requested.
     *
     * @param stdClass $mapping Mapping.
     * @return string
     */
    protected function mapping_signature(stdClass $mapping): string {
        $targetid = $mapping->itemtype == "course" ? (int) $mapping->courseid : (int) $mapping->cohortid;
        $roleid = $mapping->itemtype == "course" ? (int) $mapping->roleid : 0;

        return implode(":", [
            (int) $mapping->id,
            $mapping->itemtype,
            $targetid,
            $roleid,
        ]);
    }

    /**
     * Find a grant by mapping signature.
     *
     * @param array $grants Grants.
     * @param string $signature Signature.
     * @return int|null
     */
    protected function find_grant_index(array $grants, string $signature): ?int {
        foreach ($grants as $index => $grant) {
            if (($grant["signature"] ?? "") === $signature) {
                return $index;
            }
        }

        return null;
    }
}
