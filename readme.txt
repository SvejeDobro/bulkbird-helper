=== Bulkbird Helper ===
Contributors: bulkbird
Tags: woocommerce, bulkbird
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0-rc.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optional companion for the Bulkbird desktop app.

== Description ==

Bulkbird Helper adds a few REST endpoints that the WooCommerce REST API does not offer.
Bulkbird works without it; the plugin only unlocks extra features.

* No settings page, no menu, no notices.
* No CSS, no JavaScript, no work on storefront pages.
* Deletes nothing in your shop.
* Returns no customer data.
* Uses the WooCommerce REST API key that Bulkbird already has.

Requires WooCommerce 8.0 or newer. Multisite is not supported in version 1.

== Changelog ==

= 0.2.0 =
* GET /bulkbird/v1/media: the whole image library, with search and paging.
* POST /bulkbird/v1/media: image upload, safe to retry (client_upload_id).
* Per-user rate limiting of the plugin's endpoints.

= 0.1.0 =
* First version: authentication with WooCommerce REST keys and GET /bulkbird/v1/info.
