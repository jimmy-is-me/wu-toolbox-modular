# LINE Pay payment gateway source

Bundled payment gateway code is derived from **Pay with LINE Pay 1.4.0**.

- Source: https://wordpress.org/plugins/wpbr-linepay-tw/
- Author: WPBrewer and contributors
- License: GNU General Public License version 3
- Upstream text domain: `wpbr-linepay-tw`

WU Toolbox Modular retains its own module bootstrap and constant names. Local
adaptations keep the two callback credentials exact, apply the WooCommerce
authorization and order checks to both admin actions, and integrate the retry
schedule with the module lifecycle.
