# LINE Pay payment gateway source

Bundled payment gateway code is derived from **Pay with LINE Pay 1.3.3**.

- Source: https://wordpress.org/plugins/wpbr-linepay-tw/
- Author: WPBrewer and contributors
- License: GNU General Public License version 3
- Upstream text domain: `wpbr-linepay-tw`

WU Toolbox Modular replaces only the standalone plugin bootstrap and constant
names so the gateway can be loaded as an optional module. Payment requests,
callbacks, refunds, order metadata, block checkout support, and WooCommerce
gateway classes remain based on the supplied upstream release.
