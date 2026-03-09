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
 * webhooks.php
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define("NO_DEBUG_DISPLAY", true);

// phpcs:disable moodle.Files.RequireLogin.Missing
require("../../config.php");

use local_kopere_wpbridge\service\order_sync_service;
use local_kopere_wpbridge\service\webhook_manager;

$token = required_param("token", PARAM_ALPHANUMEXT);
$expectedtoken = get_config("local_kopere_wpbridge", "webhooktoken");

header("Content-Type: application/json; charset=utf-8");

if ($expectedtoken == "" || !hash_equals($expectedtoken, $token)) {
    http_response_code(403);
    echo json_encode([
        "status" => "error",
        "message" => get_string("error_invalidwebhooktoken", "local_kopere_wpbridge"),
    ]);
    die;
}

$rawbody = file_get_contents("php://input");
$signature = $_SERVER["HTTP_X_WC_WEBHOOK_SIGNATURE"] ?? "";

if (!webhook_manager::verify_request_signature($rawbody, $signature)) {
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => get_string("error_invalidsignature", "local_kopere_wpbridge"),
    ]);
    die;
}

try {
    $service = new order_sync_service();
    $result = $service->handle_webhook_payload($rawbody, $_SERVER);

    http_response_code(200);
    echo json_encode([
        "status" => "ok",
        "result" => $result,
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $exception->getMessage(),
    ]);
}
