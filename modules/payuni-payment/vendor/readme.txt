=== Pay with PAYUNi ===
Contributors: wpbrewer, bluestan
Tags: WooCommerce, PAYUNi, taiwan, payment, payment gateway
Requires at least: 5.9
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.8.1
License: GPLv2 or later.
License URI: http://www.gnu.org/licenses/gpl-2.0.txt

Accept payments via PAYUNi(統一金流) payment for your WooCommerce store.

== Description ==

Pay with PAYUNi(統一金流) plugin allows you to provide PAYUNi payment gateway for your WooCommerce store.
This plugin integrates with PAYUNi's UNiPaypage (UPP/整合式支付頁) service, which redirects the customer to the payment page when the customer places an order.

== Major features include ==

* Integrate with PAYUNi's UNiPaypage(UPP) service
* Support Language setting for UNiPaypage
* Support refund on backend (Credit Payment、Installment payment、Apple Pay、Google Pay、Samsung Pay)
* Support E-Invoice feature
* Compatible with High-Performance Order Storage (HPOS)

== Supported Payment Methods ==

* Credit Card payment
* Credit Card installment payment (Could enable/disable for different installments)
* Apple Pay、Google Pay、Samsung Pay
* LINE Pay
* AFTEE payment (AFTEE先享後付)
* ATM Virtual Account payment
* CVS payment
* UnionPay

== Get started with PAYUNi ==

1. [Apply PAYUNi's seller member](https://www.payuni.com.tw/signup). If you want to test the payment service in sandbox mode, please register seller member in [Sandbox](https://sandbox.payuni.com.tw/signup)
2. Install & Activate Pay with PAYUNi plugin on your WordPress website.
3. Setup the Merchant ID、Hash Key and Hash IV
4. Go to the WordPress Admin Panel. Open WooCommerce > Settings > Payments to enable your preferred payment methods.

Once your PAYUNi seller member account and your store has been approved, you can start accepting payments.
The pricing is always per transaction. No startup fees, no monthly fees.


== About PAYUNi ==

[PAYUNi (統一金流)]((https://www.payuni.com.tw/)) is a third-party payment service provider based in Taiwan. 
It offers a wide range of secure and efficient payment solutions for businesses of all sizes. 

PAYUNi's services include credit card payment, ATM virtual account payment, CVS payment, and mobile payment options, 
making it easy for merchants to accept payments from their customers. 

Additionally, PAYUNi provides shipping services such as 7-11 C2C shipping and TCat Home Delivery. 

For more information, please refer to the [PAYUNi website](https://www.payuni.com.tw/).
[PAYUNi Terms of Service](https://www.payuni.com.tw/terms).

== Changelog ==

= 1.8.1 - 2026/02/27

* ADD - Query order before auto-cancelling.
* ADD - Auto-cancelling delay setting.
* UPDATE - Add Pay Time meta for CVS Order.

= 1.8.0 - 2026/02/27

* ADD - Auto-cancelling expired CVS/ATM Orders.
* UPDATE - Change default unpaid ATM/CVS/AFTEE Order status to on-hold

= 1.7.1 - 2025/07/23 = 

* UPDATE - Restrict refundable CloseStatus. Only allow refunds when CloseStatus (請款狀態) is 2 (請款完成).

= 1.7.0 - 2025/05/04 =

* ADD - Support UnionPay
* FIX - Javascript error on checkout page

= 1.6.4 - 2024/11/18 =

* FIX - Fix conflicts with Custom Order Status Manager for WooCommerce By Bright Plugins

= 1.6.3 - 2024/11/17 =

* UPDATE - Fix WordPress 6.7 load_plugin_textdomain warning issue

= 1.6.2 - 2024/10/11 =

* FIX - Avoid order status override when receiving the PAYUNi notification

= 1.6.1 - 2024/08/22 =

* UPDATE - E-Invoice default to disabled

= 1.6.0 - 2024/08/16 =

* ADD - E-Invoice feature support

= 1.5.2 - 2024/08/06 =

* UPDATE - Fail the order when receiving the PAYUNi ATM/CVS expried notification (from Notify URL).
* FIX    - Allow setting ATM/CVS expire days to 0.

= 1.5.1 - 2024/07/08 =

* UPDATE - Refactor code for WordPress.org plugin review

= 1.5.0 - 2024/06/14 =

* ADD    - Allow to switch production and test API credentials 
* UPDATE - Refactor code and some minor update for better maintenance
* FIX    - Fix conflicts with WOOMP Credit card payment
* FIX    - Allow to refund order on different CloseStatus


= 1.4.0 - 2024/05/30 =

* ADD - Language setting for UNiPaypage

= 1.3.0 - 2024/05/15 =

* ADD - Samsung Pay support

= 1.2.0 - 2023/12/08 =

* UPDATE - rename plugin folder
* ADD - PAYUNi payment - shipping module support

= 1.1.1 – 2023/10/30 =

* FIX: Order status not changed when payment completed

= 1.1.0 - 2023-10-27 =

* ADD - Google Pay support
* ADD - HPOS Support
* ADD - Order Notify for WooCommerce Support
* FIX - Don't change order status when payment failed   

= 1.0.0 - 2023-05-27 =

* Initial release

== Frequently Asked Questions ==

= How do I ask for support if something goes wrong?

You could ask for support by sending email to service@wpbrewer.com or sending message via the facebook fanpage https://www.facebook.com/wpbrewer
