<?php
// Isolated lifecycle tests; no database or member data writes.
define('ABSPATH', __DIR__); define('EP_ROOT', 1); define('EP_PAGES', 2);
class WooCommerce {}
$options=[];$hooks=[];$enabled=true;$flushes=0;$fail=false;
$wp_rewrite=(object)['endpoints'=>[]];
function add_action($name,$callback,...$args){$GLOBALS['hooks'][$name]=$callback;}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;}
function delete_option($key){unset($GLOBALS['options'][$key]);}
function wutm_is_enabled($key){return $GLOBALS['enabled'];}
function add_rewrite_endpoint($name,$mask){$GLOBALS['wp_rewrite']->endpoints[]=[$mask,$name,$name];}
function flush_rewrite_rules($hard=true){if($hard)throw new RuntimeException('Must soft flush');$GLOBALS['flushes']++;if($GLOBALS['fail'])throw new RuntimeException('Simulated failure');$GLOBALS['options']['rewrite_rules']=['fixture'=>'index.php'];}
require dirname(__DIR__).'/core/member-loyalty-routes.php';
function route_assert($ok,$message){if(!$ok)throw new RuntimeException($message);}
$run=$hooks['wp_loaded'];
$run();route_assert($flushes===1 && count($wp_rewrite->endpoints)===2,'Initial endpoints and single soft flush');
$run();route_assert($flushes===1,'Stable requests do not flush');
wutm_member_routes_pending('member-loyalty',false);$enabled=false;$run();route_assert($flushes===2 && !$wp_rewrite->endpoints,'Disable removes both endpoints');
wutm_member_routes_pending('member-loyalty',true);$enabled=true;$run();route_assert($flushes===3 && count($wp_rewrite->endpoints)===2,'Re-enable restores endpoints once');
$fail=true;wutm_member_routes_pending('member-loyalty',true);$run();route_assert(isset($options['wutm_member_routes_error'],$options['wutm_member_routes_pending']),'Failure retains diagnostic and pending state');
echo "Member route lifecycle checks passed.\n";
