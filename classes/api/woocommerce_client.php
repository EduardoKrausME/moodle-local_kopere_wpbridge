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
 * woocommerce_client.php
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_kopere_wpbridge\api;

use coding_exception;
use curl;
use moodle_exception;

/**
 * Small WooCommerce REST API client used by the bridge.
 */
class woocommerce_client {
    /** @var string */
    protected string $storeurl;

    /** @var string */
    protected string $consumerkey;

    /** @var string */
    protected string $consumersecret;

    /**
     * Create the client from plugin configuration.
     *
     * @throws moodle_exception When configuration is incomplete.
     */
    public function __construct() {
        $this->storeurl = trim(get_config("local_kopere_wpbridge", "storeurl"));
        $this->consumerkey = trim(get_config("local_kopere_wpbridge", "consumerkey"));
        $this->consumersecret = trim(get_config("local_kopere_wpbridge", "consumersecret"));

        if ($this->storeurl == "" || $this->consumerkey == "" || $this->consumersecret == "") {
            throw new moodle_exception("error_configmissing", "local_kopere_wpbridge");
        }
    }

    /**
     * Test if the WooCommerce API is reachable.
     *
     * @return array
     * @throws moodle_exception
     */
    public function test_connection(): array {
        return $this->request("GET", "products", [
            "per_page" => 1,
            "page" => 1,
        ]);
    }

    /**
     * Get a page of completed orders from WooCommerce.
     *
     * @param int $page Page number.
     * @param int $perpage Items per page.
     * @return array
     * @throws moodle_exception
     */
    public function get_completed_orders(int $page = 1, int $perpage = 50): array {
        $response = $this->request("GET", "orders", [
            "status" => "completed",
            "orderby" => "date",
            "order" => "desc",
            "page" => $page,
            "per_page" => $perpage,
        ]);

        return $response["body"] ?? [];
    }

    /**
     * Return a list of existing WooCommerce webhooks.
     *
     * @return array
     * @throws moodle_exception
     */
    public function list_webhooks(): array {
        $response = $this->request("GET", "webhooks", [
            "page" => 1,
            "per_page" => 100,
        ]);

        return $response["body"] ?? [];
    }

    /**
     * Create a new WooCommerce webhook.
     *
     * @param string $name Webhook name.
     * @param string $topic Webhook topic.
     * @param string $deliveryurl Destination URL.
     * @param string $secret Webhook secret.
     * @return array
     * @throws moodle_exception
     */
    public function create_webhook(string $name, string $topic, string $deliveryurl, string $secret): array {
        $response = $this->request("POST", "webhooks", [], [
            "name" => $name,
            "status" => "active",
            "topic" => $topic,
            "delivery_url" => $deliveryurl,
            "secret" => $secret,
        ]);

        return $response["body"] ?? [];
    }

    /**
     * Execute an HTTP request to WooCommerce.
     *
     * @param string $method HTTP method.
     * @param string $endpoint REST endpoint without the base path.
     * @param array $query Query parameters.
     * @param array|null $body Request body.
     * @return array
     * @throws moodle_exception When the response is not successful.
     */
    public function request(string $method, string $endpoint, array $query = [], ?array $body = null): array {
        global $CFG;

        require_once("{$CFG->libdir}/filelib.php");

        $url = $this->build_url($endpoint);
        $query["consumer_key"] = $this->consumerkey;
        $query["consumer_secret"] = $this->consumersecret;

        $curl = new curl();
        $options["CURLOPT_SSL_VERIFYPEER"] = false;
        $options["CURLOPT_SSL_VERIFYHOST"] = false;
        $options["CURLOPT_TIMEOUT"] = 30;
        $options["CURLOPT_FOLLOWLOCATION"] = true;
        $options["CURLOPT_HTTPHEADER"] = [
            "Accept: application/json",
            "Content-Type: application/json",
        ];

        if ($method == "GET") {
            $response = $curl->get($url, $query, $options);
        } else if ($method == "POST") {
            $response = $curl->post(
                $url . "?" . http_build_query($query),
                json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $options
            );
        } else {
            throw new coding_exception("Unsupported HTTP method.");
        }

        $info = $curl->get_info();
        $statuscode = ($info["http_code"] ?? 0);
        $decoded = json_decode($response, true);

        if ($statuscode < 200 || $statuscode >= 300) {
            throw new moodle_exception(
                "error_configmissing",
                "local_kopere_wpbridge",
                "",
                null,
                "WooCommerce request failed. HTTP {$statuscode}. Body: " . $response
            );
        }

        return [
            "status" => $statuscode,
            "body" => is_array($decoded) ? $decoded : [],
            "info" => $info,
            "raw" => $response,
        ];
    }

    /**
     * Build the full WooCommerce API URL.
     *
     * @param string $endpoint Relative endpoint.
     * @return string
     */
    protected function build_url(string $endpoint): string {
        $base = rtrim($this->storeurl, "/");
        $endpoint = ltrim($endpoint, "/");
        return $base . "/wp-json/wc/v3/" . $endpoint;
    }
}
