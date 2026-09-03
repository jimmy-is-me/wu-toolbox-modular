=== Pay with LINE Pay ===
Contributors: wpbrewer, bluestan
Tags: WooCommerce, payment, LINE Pay, LINE, payment gateway
Requires at least: 5.9
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.3.3
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept payments via LINE Pay for your ecommerce store.

== Description ==


Pay with LINE Pay allows you to provide LINE Pay payment gateway for the customers.

Major features include:

* General Payment
* Refund (Partial refund allowed)
* Support country: Taiwan
* Support Plugin: WooCommerce
* Compatible with High-Performance Order Storage (HPOS)
* Compatible with Cart & Checkout Blocks

== Changelog ==

= 1.3.3 - 2025-11/24
* FIX - Avoid multiple confirm api called
* UPDATE - Change order status to on-hold for other error when confirm failed

= 1.3.2 - 2025-09-09 = 
* UPDATE - Increase Requires PHP version to 8.0
* UPDATE - Minor adjustment for block checkout compatibility.
* FIX - Avoid dynamic properties and support PHP 8.2 (also PHP 8.3)

= 1.3.1 - 2024-11-05 = 
* UPDATE - Adjust the redirect url, so it could work with any permalink setting.
* FIX - Error when request API failed.

= 1.3.0 - 2024-05-20 = 
* ADD - Support Cart & Checkout Blocks

= 1.2.2 - 2024-04-16 = 

* FIX - Fix potential conflict with other plugins and provide backward compatibility.
* ADD - Add WooCommerce as a require plugin.

= 1.2.1 - 2024-03-29 = 

* FIX - Fix setting tab return type to avoid potential conflict with other plugins.
* UPDATE - Update debug log description due to wc_get_log_file_path is deprecated after 8.6.0

= 1.2.0 - 2024-02-20 =

* Add - Allow to set fail order status
* Add - Allow to enable detail payment status note in order note

= 1.1.4 - 2023-10-21 =

* Update: only show metabox if the payment method is LINE Pay

= 1.1.3 - 2023-10-18 =

* Update: Compatible with HPOS
* Update: Increase Requires PHP version to 7.4

= 1.1.2 - 2023-10-11 =

* Update: fix fatal error when activating the plugin

= 1.1.1 - 2023-10-09 =

* Update: fix partial refund

= 1.1.0 - 2023-04-22 =

* Updated: change plugin name and slug.

= 1.0.1 - 2023-04-08 =

* Fixed: checkout error when currency scale is not set to zero.

= 1.0.0 - 2022-07-05 =

* Initial release

== Frequently Asked Questions ==

= Does this plugin support merchant in other countries like Japan or Thailand =

No, it only support merchants based in Taiwan.

= How do I ask for support if something goes wrong?

You could ask for support by sending email to service@wpbrewer.com or sending message via the facebook fanpage https://www.facebook.com/wpbrewer

= How can I report security bugs? = 

You can report security bugs through the Patchstack Vulnerability Disclosure Program. The Patchstack team help validate, triage and handle any security vulnerabilities. [Report a security vulnerability.](https://patchstack.com/database/vdp/wpbr-linepay-tw)
