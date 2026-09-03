# PAYUNi payment gateway source

Bundled payment gateway code is derived from **Pay with PAYUNi 1.8.1**.

- Source: https://wordpress.org/plugins/wpbr-payuni-payment/
- Author: WPBrewer and contributors
- License: GNU General Public License version 2 or later
- Upstream text domain: `wpbr-payuni-payment`

WU Toolbox Modular replaces only the standalone plugin bootstrap and constant
names so the gateway can be loaded as an optional module. Payment requests,
callbacks, refunds, order metadata, settings, and WooCommerce gateway classes
remain based on the supplied upstream release.
