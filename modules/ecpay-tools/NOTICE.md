# Third-party source notices

ECPay payment/shipping and invoice implementation is adapted from the user-supplied Woomp 3.5.17 source archive (https://github.com/zenbuapps/woomp), GPL-2.0-or-later. Original copyright and license files are retained in vendor. wp-metabox is MIT licensed.

Integration changes: selective ECPay bootstrap (not the Woomp all-provider bootstrap), WU dashboard/settings entry, separate invoice settings, and capability/nonce guard for invoice mutations. Existing provider identifiers and callback verification are retained. Other provider implementations bundled in the RY source are not initialized by this module.

Do not enable together with Woomp/RY WooCommerce Tools or another ECPay integration. Merchant sandbox/end-to-end tests and checkout-block compatibility have not been verified. Test all payment callbacks, logistics creation/status notifications, invoice issue and void operations before production use.
