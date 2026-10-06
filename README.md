# Kopere WP Bridge

Kopere WP Bridge connects WooCommerce sales to Moodle access.

A WooCommerce product can grant access to one or more Moodle courses or cohorts. The bridge receives WooCommerce webhooks, keeps a local order mirror and also runs a scheduled reconciliation task so a temporary webhook failure does not leave paid users without access.

The plugin is intentionally focused on access synchronization. WooCommerce remains the commercial source of truth and Moodle remains responsible for users, enrolments, cohorts, roles and messaging.

## What it does

- Maps WooCommerce product IDs to Moodle courses.
- Maps WooCommerce product IDs to Moodle cohorts.
- Supports multiple Moodle destinations for the same WooCommerce product.
- Creates Moodle users automatically when a customer does not already exist.
- Uses WooCommerce customer ID as the preferred identity after the first successful link, with email used as the bootstrap/fallback identity.
- Enrols users through the Moodle manual enrolment plugin.
- Adds users to cohorts through the Moodle Cohort API.
- Receives `order.created` and `order.updated` webhooks.
- Validates both the endpoint token and the WooCommerce HMAC signature.
- Mirrors orders and line items locally for reconciliation and auditing.
- Uses an order-level Moodle lock so webhook and CRON cannot process the same order concurrently.
- Tracks which course/cohort grants were actually created by the bridge.
- Revokes bridge-created access when a WooCommerce order becomes cancelled, refunded, failed or trashed.
- Revokes bridge-created access when a line item is removed from an otherwise completed order.
- Never removes existing Moodle access merely because a product mapping was edited, disabled or deleted.
- Reprocesses previous completed purchases when a new/enabled mapping is saved, so old buyers can receive newly added access.
- Retries temporary processing failures with exponential backoff.
- Sends access notifications through the Moodle Message API.
- Implements Moodle Privacy API support for the mirrored personal data.

## Synchronization model

There are two complementary paths.

### Webhooks

WooCommerce pushes order changes immediately to:

`/local/kopere_wpbridge/webhooks.php`

The complete URL, including the endpoint token, is shown on the plugin settings page. The request body must also contain a valid WooCommerce webhook HMAC signature.

### Scheduled reconciliation

The scheduled task runs every 10 minutes and requests orders modified since the last successful synchronization cursor.

This is deliberately different from only fetching the most recent completed orders. The cursor is based on WooCommerce `date_modified_gmt`, uses a small overlap to protect against boundary/clock issues and paginates until every changed order has been consumed. This allows the task to see not only new completed purchases but also later cancellation/refund changes.

Class:

`\local_kopere_wpbridge\task\sync_orders`

## Access lifecycle

For a completed order, every line item is matched against the active mappings for its WooCommerce product.

The plugin stores grant metadata per order item, including the mapping signature, destination, whether the bridge actually created the Moodle enrolment/cohort membership and whether that grant is currently active.

This distinction matters when an order is later refunded. If the user already had a manual enrolment before the sale, the bridge records the mapping but does not claim ownership of that enrolment, therefore it will not remove it later.

If another completed WooCommerce purchase still grants the same course or cohort, refunding one order also does not remove that access.

### Mapping changes are additive

Editing, disabling or deleting a mapping does not remove existing Moodle access.

When an enabled mapping is added or changed, previous completed purchases of that product are queued for reconciliation. Missing access is added, while access created by older mappings remains untouched.

This behavior is intentional: commercial order state can revoke access, but an administrator changing the mapping configuration cannot unexpectedly un-enrol existing students.

## Customer identity

WooCommerce `customer_id` is stored with the mirrored order.

When the bridge has already processed an order for that customer ID, future purchases reuse the same Moodle user even if the billing email later changes. For a customer that has never been linked before, the bridge falls back to the billing email.

If more than one active Moodle account has the same email address, the bridge stops that order with an error instead of choosing an arbitrary account.

Guest WooCommerce orders (`customer_id = 0`) continue to use email as the identity key.

## Retry behavior

Temporary failures are not retried continuously.

Each failed order item stores:

- attempt count;
- last error;
- next retry time.

Retries use exponential backoff starting at one minute and are capped at six hours. A new WooCommerce update can make an item immediately eligible for processing again.

## Required configuration

Configure:

- WooCommerce store URL;
- WooCommerce REST API consumer key;
- WooCommerce REST API consumer secret.

Destination courses must have an enabled manual enrolment instance.

The WooCommerce REST API key requires enough permission to read orders and manage webhooks because the plugin automatically checks/creates its `order.created` and `order.updated` hooks.

## SSL compatibility note

The WooCommerce HTTP client intentionally disables cURL peer/host certificate verification.

This is retained for compatibility with hosting environments where the PHP/cURL CA bundle fails to validate otherwise valid Let's Encrypt certificate chains even though browsers can access the same store normally. It is documented directly in `classes/api/woocommerce_client.php` so the reason is not mistaken for an accidental cURL configuration.

## Administration

Dashboard:

`/local/kopere_wpbridge/`

Product mappings:

`/local/kopere_wpbridge/mappings.php`

Plugin settings:

`/admin/settings.php?section=local_kopere_wpbridge`

A mapping contains:

- WooCommerce product ID;
- destination type: course or cohort;
- destination ID;
- role for course enrolment;
- enabled state.

## Order and item states

The local mirror uses item states to make processing idempotent:

- `pending`: waiting for reconciliation;
- `processed`: mappings were reconciled;
- `ignored`: no active mapping exists for the product;
- `error`: processing failed and is waiting for retry;
- `removed`: line item no longer exists in the WooCommerce order;
- `revoked`: the commercial order state caused bridge-managed access to be revoked.

WooCommerce can deliver the same webhook more than once. Repeated delivery is expected and does not by itself create duplicate grants.

## Important behavior on upgrades

When upgrading from older versions, previously processed completed items are queued once so the plugin can build grant metadata.

This migration is conservative: if the Moodle access already exists, the bridge records it as pre-existing instead of assuming ownership. As a result, legacy enrolments are not automatically removed by a future refund unless the bridge can prove that it created the access itself after grant tracking was introduced.
