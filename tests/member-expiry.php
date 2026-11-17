<?php
define('ABSPATH',__DIR__);function wp_timezone(){return new DateTimeZone('Asia/Taipei');}function current_datetime(){return new DateTimeImmutable('2026-11-17 12:30:00',wp_timezone());}class WP_Error{function __construct(...$a){}}
require dirname(__DIR__).'/modules/member-loyalty/manual-expiry.php';
function verify407($yes){if(!$yes)throw new RuntimeException('Expiry assertion failed');}
verify407(wutm_member_manual_expiry('keep','','','2026-12-01 10:20:30',365)==='2026-12-01 10:20:30');
verify407(wutm_member_manual_expiry('date','2026-12-25T18:45','',null,365)==='2026-12-25 18:45:00');
verify407(wutm_member_manual_expiry('date','2026-02-31T18:45','',null,365) instanceof WP_Error);
verify407(wutm_member_manual_expiry('shift','',-7,'2026-12-01 10:20:30',365)==='2026-11-24 10:20:30');
verify407(wutm_member_manual_expiry('shift','',30,null,365) instanceof WP_Error);
verify407(wutm_member_manual_expiry('days','',30,null,365)==='2026-12-17 12:30:00');
verify407(wutm_member_manual_expiry('permanent','','','2026-12-01 10:20:30',365)===null);
echo "Manual member expiry checks passed.\n";
