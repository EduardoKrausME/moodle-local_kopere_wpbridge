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
 * sync_orders.php
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_kopere_wpbridge\task;

use coding_exception;
use core\task\scheduled_task;
use local_kopere_wpbridge\service\order_sync_service;
use moodle_exception;

/**
 * Scheduled task that imports recent completed WooCommerce orders.
 */
class sync_orders extends scheduled_task {
    /**
     * Return the task display name.
     *
     * @return string
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string("task_syncorders", "local_kopere_wpbridge");
    }

    /**
     * Execute the scheduled synchronization.
     *
     * @return void
     * @throws moodle_exception
     * @throws coding_exception
     */
    public function execute(): void {
        $service = new order_sync_service();
        $service->sync_recent_completed_orders();
    }
}
