# local_kopere_wpbridge

Bridge between WooCommerce and Moodle.

## Features

- Map WooCommerce product IDs to Moodle courses
- Map WooCommerce product IDs to Moodle cohorts
- Receive order webhooks with token validation
- Validate WooCommerce HMAC signature
- Scheduled task to re-sync completed sales
- Auto-create Moodle users when they do not exist
- Enrol users through manual enrolment
- Add users to cohorts
- Send email notifications through Moodle message API

## Required configuration

- Store URL
- WooCommerce consumer key
- WooCommerce consumer secret
- Manual enrolment enabled in destination courses

## Main pages

- `/local/kopere_wpbridge/`
- `/local/kopere_wpbridge/mappings.php`
- `/admin/settings.php?section=local_kopere_wpbridge`

## Webhook endpoint

The settings page shows the complete URL with token.

Example:

`https://moodle.example.com/local/kopere_wpbridge/webhooks.php?token=...`

## Task

The scheduled task checks recent completed orders and processes any pending local items.

Class:

`\local_kopere_wpbridge\task\sync_orders`
