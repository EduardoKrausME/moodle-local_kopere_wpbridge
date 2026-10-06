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
 * mapping_repository.php
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_kopere_wpbridge\service;

use dml_exception;
use stdClass;

/**
 * Repository for product mappings.
 */
class mapping_repository {
    /**
     * Return all mappings.
     *
     * @param int|null $courseid Optional course filter.
     * @return array
     * @throws dml_exception
     */
    public function get_all(?int $courseid = null): array {
        global $DB;

        $sql = "SELECT m.*, c.fullname AS coursename, ch.name AS cohortname
                  FROM {local_kopere_wpbridge_map} m
             LEFT JOIN {course} c ON c.id = m.courseid
             LEFT JOIN {cohort} ch ON ch.id = m.cohortid";

        $params = [];
        if ($courseid) {
            $sql .= " WHERE m.courseid = :courseid";
            $params["courseid"] = $courseid;
        }

        $sql .= " ORDER BY m.productid ASC, m.itemtype ASC, c.fullname ASC, ch.name ASC";
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Return a single mapping by ID.
     *
     * @param int $id Mapping ID.
     * @return stdClass|null
     * @throws dml_exception
     */
    public function get(int $id): ?stdClass {
        global $DB;

        $record = $DB->get_record("local_kopere_wpbridge_map", ["id" => $id]);
        return $record ?: null;
    }

    /**
     * Save or update a mapping.
     *
     * Adding or changing an enabled mapping queues previous completed purchases for additive
     * reconciliation. Removing or disabling a mapping never removes an existing Moodle enrolment.
     *
     * @param stdClass $record Mapping record.
     * @return int
     * @throws dml_exception
     */
    public function save(stdClass $record): int {
        global $DB;

        $now = time();
        $record->timemodified = $now;

        if (!empty($record->id)) {
            $DB->update_record("local_kopere_wpbridge_map", $record);
            $id = (int) $record->id;
        } else {
            $record->timecreated = $now;
            $id = (int) $DB->insert_record("local_kopere_wpbridge_map", $record);
        }

        if (!empty($record->enabled)) {
            $this->queue_product_reconciliation((int) $record->productid);
        }

        return $id;
    }

    /**
     * Delete a mapping.
     *
     * Existing Moodle access is intentionally left untouched.
     *
     * @param int $id Mapping ID.
     * @return void
     * @throws dml_exception
     */
    public function delete(int $id): void {
        global $DB;
        $DB->delete_records("local_kopere_wpbridge_map", ["id" => $id]);
    }

    /**
     * Return active mappings for a specific product ID.
     *
     * @param int $productid WooCommerce product ID.
     * @return array
     * @throws dml_exception
     */
    public function get_active_by_product(int $productid): array {
        global $DB;

        return $DB->get_records("local_kopere_wpbridge_map", [
            "productid" => $productid,
            "enabled" => 1,
        ]);
    }

    /**
     * Queue completed purchases of a product so newly added mappings are applied to previous buyers.
     *
     * @param int $productid WooCommerce product ID.
     * @return void
     * @throws dml_exception
     */
    protected function queue_product_reconciliation(int $productid): void {
        global $DB;

        $sql = "UPDATE {local_kopere_wpbridge_item}
                   SET status = :pending,
                       nextretry = 0,
                       timemodified = :now
                 WHERE productid = :productid
                   AND status <> :removed
                   AND orderid IN (
                       SELECT id
                         FROM {local_kopere_wpbridge_order}
                        WHERE status = :completed
                   )";

        $DB->execute($sql, [
            "pending" => "pending",
            "now" => time(),
            "productid" => $productid,
            "removed" => "removed",
            "completed" => "completed",
        ]);
    }
}
