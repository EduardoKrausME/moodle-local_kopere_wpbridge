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

        if ($order->status == "completed") {
            return $this->process_order($order);
        }

        return [
            "saved" => true,
            "processed" => false,
            "status" => $order->status,
        ];
    }

    /**
     * Poll recent completed orders from WooCommerce and process them.
     *
     * @return void
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function sync_recent_completed_orders(): void {
        $client = new woocommerce_client();
        $perpage = 50;
        $maxpages = 5;

        for ($page = 1; $page <= $maxpages; $page++) {
            $orders = $client->get_completed_orders($page, $perpage);
            if (!$orders) {
                break;
            }

            foreach ($orders as $payload) {
                try {
                    $order = $this->orders->upsert_from_payload($payload, "task");
                    if ($order->status == "completed") {
                        $this->process_order($order);
                    }
                } catch (Throwable $exception) {
                    $this->messages->notify_admin_issue($exception->getMessage());
                }
            }

            if (count($orders) < $perpage) {
                break;
            }
        }

        $this->process_pending_items();
    }

    /**
     * Process pending items that may already be stored locally.
     *
     * @param int $limit Maximum items.
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     */
    public function process_pending_items(int $limit = 100): void {
        $items = $this->orders->get_pending_items($limit);

        foreach ($items as $item) {
            try {
                $order = $this->orders->get_order_by_externalid($item->externalorderid);
                if (!$order) {
                    continue;
                }

                $this->process_order($order);
            } catch (Throwable $exception) {
                $this->orders->mark_error($item->id, $exception->getMessage());
                $this->messages->notify_admin_issue($exception->getMessage());
            }
        }
    }

    /**
     * Process all still-open items of a mirrored order.
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

                $messages = [];
                foreach ($mappings as $mapping) {
                    $messages[] = $this->enrolment->apply_mapping($user->id, $mapping);
                }

                $finalmessage = implode("; ", $messages);
                $this->orders->mark_processed($item->id, $user->id, $finalmessage);
                $results[] = $item->productname . " => " . $finalmessage;
            } catch (Throwable $exception) {
                $this->orders->mark_error($item->id, $exception->getMessage());
                $this->messages->notify_admin_issue($exception->getMessage());
            }
        }

        if ($results) {
            $this->messages->send_user_access_email($user, $results, $order->externalid);
        }

        return [
            "saved" => true,
            "processed" => !empty($results),
            "items" => $results,
        ];
    }
}
