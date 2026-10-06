<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Upgrade steps for local_kopere_wpbridge.
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

/**
 * Upgrade local_kopere_wpbridge.
 *
 * @param int $oldversion Installed version.
 * @return bool
 */
function xmldb_local_kopere_wpbridge_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026100600) {
        $ordertable = new xmldb_table("local_kopere_wpbridge_order");
        $customerid = new xmldb_field(
            "customerid",
            XMLDB_TYPE_INTEGER,
            "10",
            null,
            XMLDB_NOTNULL,
            null,
            "0",
            "externalid"
        );

        if (!$dbman->field_exists($ordertable, $customerid)) {
            $dbman->add_field($ordertable, $customerid);
        }

        $customerindex = new xmldb_index("customerid", XMLDB_INDEX_NOTUNIQUE, ["customerid"]);
        if (!$dbman->index_exists($ordertable, $customerindex)) {
            $dbman->add_index($ordertable, $customerindex);
        }

        $itemtable = new xmldb_table("local_kopere_wpbridge_item");

        $attempts = new xmldb_field(
            "attempts",
            XMLDB_TYPE_INTEGER,
            "10",
            null,
            XMLDB_NOTNULL,
            null,
            "0",
            "message"
        );
        if (!$dbman->field_exists($itemtable, $attempts)) {
            $dbman->add_field($itemtable, $attempts);
        }

        $nextretry = new xmldb_field(
            "nextretry",
            XMLDB_TYPE_INTEGER,
            "10",
            null,
            XMLDB_NOTNULL,
            null,
            "0",
            "attempts"
        );
        if (!$dbman->field_exists($itemtable, $nextretry)) {
            $dbman->add_field($itemtable, $nextretry);
        }

        $lasterror = new xmldb_field(
            "lasterror",
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            "nextretry"
        );
        if (!$dbman->field_exists($itemtable, $lasterror)) {
            $dbman->add_field($itemtable, $lasterror);
        }

        $grants = new xmldb_field(
            "grants",
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            "lasterror"
        );
        if (!$dbman->field_exists($itemtable, $grants)) {
            $dbman->add_field($itemtable, $grants);
        }

        $retryindex = new xmldb_index("status-nextretry", XMLDB_INDEX_NOTUNIQUE, ["status", "nextretry"]);
        if (!$dbman->index_exists($itemtable, $retryindex)) {
            $dbman->add_index($itemtable, $retryindex);
        }

        // Existing processed items are queued for a conservative grant-state backfill.
        // apply_mapping() detects pre-existing Moodle access, so legacy enrolments are recorded
        // but are not assumed to have been created by the bridge and therefore will not be revoked.
        $DB->execute(
            "UPDATE {local_kopere_wpbridge_item}
                SET status = :pending,
                    nextretry = 0
              WHERE status = :processed
                AND orderid IN (
                    SELECT id
                      FROM {local_kopere_wpbridge_order}
                     WHERE status = :completed
                )",
            [
                "pending" => "pending",
                "processed" => "processed",
                "completed" => "completed",
            ]
        );

        upgrade_plugin_savepoint(true, 2026100600, "local", "kopere_wpbridge");
    }

    return true;
}
