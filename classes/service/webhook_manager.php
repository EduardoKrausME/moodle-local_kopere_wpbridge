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
 * webhook_manager.php
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_kopere_wpbridge\service;

use core\exception\coding_exception;
use core\exception\moodle_exception;
use dml_exception;
use Exception;
use local_kopere_wpbridge\api\woocommerce_client;
use moodle_url;
use Random\RandomException;
use Throwable;

/**
 * Helper class for webhook token generation and webhook synchronization.
 */
class webhook_manager {
    /**
     * Ensure a persistent token exists and return it.
     *
     * @return string
     * @throws RandomException
     * @throws dml_exception
     */
    public static function ensure_token(): string {
        $token = get_config("local_kopere_wpbridge", "webhooktoken");
        if ($token != "") {
            return $token;
        }

        $token = bin2hex(random_bytes(20));
        set_config("webhooktoken", $token, "local_kopere_wpbridge");
        return $token;
    }

    /**
     * Build the Moodle webhook URL with token.
     *
     * @return string
     * @throws RandomException
     * @throws coding_exception
     * @throws moodle_exception
     * @throws dml_exception
     */
    public static function get_webhook_url(): string {
        global $CFG;

        $url = new moodle_url("/local/kopere_wpbridge/webhooks.php", [
            "token" => self::ensure_token(),
        ]);

        return $CFG->wwwroot . $url->out_as_local_url(false);
    }

    /**
     * Test the WooCommerce connection and ensure remote webhooks exist.
     *
     * @return void
     * @throws Exception
     */
    public static function after_settings_save(): void {
        try {
            $client = new woocommerce_client();
            $client->test_connection();
            self::ensure_webhooks($client);

            set_config("connectionstatus", "ok", "local_kopere_wpbridge");
            set_config(
                "connectionmessage",
                get_string("settings_testok", "local_kopere_wpbridge"),
                "local_kopere_wpbridge"
            );
            set_config("lasttesttime", time(), "local_kopere_wpbridge");
        } catch (Throwable $exception) {
            set_config("connectionstatus", "failed", "local_kopere_wpbridge");
            set_config(
                "connectionmessage",
                get_string("settings_testfailed", "local_kopere_wpbridge", $exception->getMessage()),
                "local_kopere_wpbridge"
            );
            set_config("lasttesttime", time(), "local_kopere_wpbridge");
        }
    }

    /**
     * Verify the WooCommerce webhook signature.
     *
     * @param string $rawbody Raw POST body.
     * @param string $signature Signature received from WooCommerce.
     * @return bool
     * @throws dml_exception
     */
    public static function verify_request_signature(string $rawbody, string $signature): bool {
        $token = get_config("local_kopere_wpbridge", "webhooktoken");

        if ($token == "" || $signature == "") {
            return false;
        }

        $expected = base64_encode(hash_hmac("sha256", $rawbody, $token, true));
        return hash_equals($expected, $signature);
    }

    /**
     * Create required remote webhooks when they do not exist.
     *
     * @param woocommerce_client $client API client.
     * @return void
     * @throws RandomException
     * @throws Exception
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    protected static function ensure_webhooks(woocommerce_client $client): void {
        $webhooks = $client->list_webhooks();
        $deliveryurl = self::get_webhook_url();
        $secret = self::ensure_token();

        $topics = [
            "order.created",
            "order.updated",
        ];

        foreach ($topics as $topic) {
            if (self::has_webhook($webhooks, $topic, $deliveryurl)) {
                continue;
            }

            $created = $client->create_webhook(
                "Moodle " . $topic,
                $topic,
                $deliveryurl,
                $secret
            );

            $configkey = "webhookid_" . str_replace(".", "_", $topic);
            set_config($configkey, ($created["id"] ?? 0), "local_kopere_wpbridge");
        }
    }

    /**
     * Check if the exact webhook already exists.
     *
     * @param array $webhooks Existing webhooks.
     * @param string $topic Topic to search for.
     * @param string $deliveryurl Delivery URL to search for.
     * @return bool
     */
    protected static function has_webhook(array $webhooks, string $topic, string $deliveryurl): bool {
        foreach ($webhooks as $webhook) {
            $webhooktopic = $webhook["topic"] ?? "";
            $webhookurl = $webhook["delivery_url"] ?? "";
            $status = $webhook["status"] ?? "";

            if ($webhooktopic == $topic && $webhookurl == $deliveryurl && $status != "disabled") {
                return true;
            }
        }

        return false;
    }
}
