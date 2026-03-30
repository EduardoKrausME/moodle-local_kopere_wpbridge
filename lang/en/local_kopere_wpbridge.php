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
 * local_kopere_wpbridge.php
 *
 * @package   local_kopere_wpbridge
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$string['actions'] = 'Actions';
$string['adminnotification_body'] = 'An issue was detected in the WooCommerce bridge.

{$a}';
$string['adminnotification_subject'] = 'WP Bridge issue';
$string['back'] = 'Back';
$string['dashboard_laststatus'] = 'Connection status';
$string['dashboard_mappingcount'] = 'Mappings';
$string['dashboard_openui'] = 'Open mappings';
$string['dashboard_pendingcount'] = 'Pending items';
$string['dashboard_processedcount'] = 'Processed items';
$string['dashboard_settings'] = 'Settings';
$string['dashboard_subtitle'] = 'Sync completed sales into Moodle courses and cohorts.';
$string['dashboard_title'] = 'WooCommerce to Moodle bridge';
$string['error_configmissing'] = 'WooCommerce settings are incomplete.';
$string['error_invalidsignature'] = 'Invalid WooCommerce webhook signature.';
$string['error_invalidwebhooktoken'] = 'Invalid webhook token.';
$string['error_missingemail'] = 'The order does not include a customer email.';
$string['error_missingorderid'] = 'Missing WooCommerce order ID.';
$string['error_nomanualenrol'] = 'No active manual enrolment instance was found in the course.';
$string['error_nomapping'] = 'No active mapping found for this product.';
$string['kopere_wpbridge:manage'] = 'Manage WooCommerce bridge';
$string['kopere_wpbridge:view'] = 'View WooCommerce bridge';
$string['manage'] = 'Manage WooCommerce bridge';
$string['mapping_add'] = 'Add mapping';
$string['mapping_cohort'] = 'Cohort';
$string['mapping_course'] = 'Course';
$string['mapping_delete'] = 'Delete mapping';
$string['mapping_delete_confirm'] = 'Do you really want to delete this mapping?';
$string['mapping_deleted'] = 'Mapping deleted successfully.';
$string['mapping_edit'] = 'Edit mapping';
$string['mapping_enabled'] = 'Enabled';
$string['mapping_itemtype'] = 'Destination type';
$string['mapping_itemtype_cohort'] = 'Cohort';
$string['mapping_itemtype_course'] = 'Course';
$string['mapping_missingcohort'] = 'Select a cohort for cohort mapping.';
$string['mapping_missingcourse'] = 'Select a course for course mapping.';
$string['mapping_productid'] = 'WooCommerce product ID';
$string['mapping_role'] = 'Role for course enrolment';
$string['mapping_saved'] = 'Mapping saved successfully.';
$string['mappings'] = 'Mappings';
$string['messageprovider_syncnotification'] = 'WP Bridge notifications';
$string['ordernotification_body'] = 'Hello {$a->firstname},

Your order {$a->orderid} was processed successfully.

Applied access:
{$a->items}

You can now access Moodle at:
{$a->siteurl}

Regards,
{$a->sitename}';
$string['ordernotification_subject'] = 'Your Moodle access is ready';
$string['pluginname'] = 'Kopere WP Bridge';
$string['privacy:metadata:core_message'] = 'The plugin uses the Moodle messaging subsystem to send access confirmation and administrator notification messages.';
$string['privacy:metadata:local_kopere_wpbridge_item'] = 'Stores mirrored WooCommerce order items and the result of processing them in Moodle.';
$string['privacy:metadata:local_kopere_wpbridge_item:externalitemid'] = 'The external WooCommerce order item ID.';
$string['privacy:metadata:local_kopere_wpbridge_item:externalorderid'] = 'The external WooCommerce order ID associated with this item.';
$string['privacy:metadata:local_kopere_wpbridge_item:message'] = 'The processing result message stored for the order item.';
$string['privacy:metadata:local_kopere_wpbridge_item:orderid'] = 'The local mirrored order ID.';
$string['privacy:metadata:local_kopere_wpbridge_item:payload'] = 'The raw WooCommerce payload stored for the mirrored order item.';
$string['privacy:metadata:local_kopere_wpbridge_item:productid'] = 'The WooCommerce product ID purchased by the user.';
$string['privacy:metadata:local_kopere_wpbridge_item:productname'] = 'The product name copied from the WooCommerce order.';
$string['privacy:metadata:local_kopere_wpbridge_item:quantity'] = 'The quantity purchased.';
$string['privacy:metadata:local_kopere_wpbridge_item:status'] = 'The processing status of the mirrored order item.';
$string['privacy:metadata:local_kopere_wpbridge_item:timecreated'] = 'The time when the mirrored item was first stored.';
$string['privacy:metadata:local_kopere_wpbridge_item:timemodified'] = 'The time when the mirrored item was last updated.';
$string['privacy:metadata:local_kopere_wpbridge_item:userid'] = 'The Moodle user who received the access related to the order item.';
$string['privacy:metadata:local_kopere_wpbridge_order'] = 'Stores mirrored WooCommerce order data used to identify the customer and process Moodle access.';
$string['privacy:metadata:local_kopere_wpbridge_order:currency'] = 'The order currency.';
$string['privacy:metadata:local_kopere_wpbridge_order:email'] = 'The customer email address from the WooCommerce order.';
$string['privacy:metadata:local_kopere_wpbridge_order:externalid'] = 'The external WooCommerce order ID.';
$string['privacy:metadata:local_kopere_wpbridge_order:firstname'] = 'The customer first name from the WooCommerce order.';
$string['privacy:metadata:local_kopere_wpbridge_order:lastname'] = 'The customer last name from the WooCommerce order.';
$string['privacy:metadata:local_kopere_wpbridge_order:payload'] = 'The raw WooCommerce payload stored for the mirrored order.';
$string['privacy:metadata:local_kopere_wpbridge_order:source'] = 'Whether the order was stored from a webhook or from the scheduled sync task.';
$string['privacy:metadata:local_kopere_wpbridge_order:status'] = 'The mirrored order status.';
$string['privacy:metadata:local_kopere_wpbridge_order:timecreated'] = 'The time when the mirrored order was first stored.';
$string['privacy:metadata:local_kopere_wpbridge_order:timemodified'] = 'The time when the mirrored order was last updated.';
$string['privacy:metadata:local_kopere_wpbridge_order:total'] = 'The order total amount.';
$string['privacy:path:orders'] = 'WooCommerce orders';
$string['savechanges'] = 'Save changes';
$string['settings_consumerkey'] = 'Consumer key';
$string['settings_consumersecret'] = 'Consumer secret';
$string['settings_debug'] = 'Debug mode';
$string['settings_notconfigured'] = 'Connection is not configured yet.';
$string['settings_section'] = 'WooCommerce connection';
$string['settings_statusheading'] = 'Last connection test';
$string['settings_storeurl'] = 'WooCommerce URL';
$string['settings_storeurl_desc'] = 'Example: https://example.com';
$string['settings_testfailed'] = 'Connection test failed: {$a}';
$string['settings_testok'] = 'Connection tested successfully and webhook check finished.';
$string['settings_webhookheading'] = 'Webhook endpoint';
$string['settings_webhookheading_desc'] = 'Use this URL in WooCommerce. The token is always required in the query string.';
$string['settings_webhookurl'] = 'Webhook URL';
$string['status_error'] = 'Error';
$string['status_failed'] = 'Failed';
$string['status_ignored'] = 'Ignored';
$string['status_ok'] = 'OK';
$string['status_pending'] = 'Pending';
$string['status_processed'] = 'Processed';
$string['task_syncorders'] = 'Sync WooCommerce completed orders';
$string['wpbridge'] = 'WP Bridge';
