<?php

namespace WuDigital\Payuni\Shipping\Utils;

class OrderMeta {
    
	const StoreId           = '_shipping_payuni_storeid';
	const StoreName         = '_shipping_payuni_storename';
	const StoreAddr         = '_shipping_payuni_storeaddress';
	
	const ShipNo            = '_payuni_shipping_sno'; // 物流單查詢編號(Seven=Odno+ValidationNo, 黑貓=OBTNumber)
	const GoodsType         = '_payuni_shipping_goods_type';// 溫層.
	const LgsType           = '_payuni_shipping_lgs_type';// 溫送方式.
	const ShipType 		    = '_payuni_shipping_ship_type';// 通路. 1:seven, 2:黑貓
	const ShipTradeNo       = '_payuni_shipping_trade_no'; // UNi 物流序號.
	const Odno              = '_payuni_shipping_odno'; // 物流商出貨編號(8碼).
	
	const FileNo            = '_payuni_shipping_file_no'; // 黑貓標籤檔案編號.
	const PartnerId         = '_payuni_shipping_partnerId'; //
	const ValidationNo      = '_payuni_shipping_validation_no'; // 物流商驗證碼如需使用 IboN 列印請搭配 paymentno 使用.
	const PackageSpec       = '_payuni_shipping_package_spec'; // 黑貓包裹規格.

	const ShipStatus        = '_payuni_shipping_ship_status'; // 物流狀態.
	const ShipStatusDesc    = '_payuni_shipping_ship_status_desc'; // 物流狀態描述.
	const ShipStatusTime    = '_payuni_shipping_ship_status_time'; // 物流狀態時間.
	
}
