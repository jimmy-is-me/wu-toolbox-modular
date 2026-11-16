<?php
// Privacy and expiry checks against the actual card renderer/service.
define('ABSPATH', __DIR__);define('WUTM_URL','https://fixture.test/');define('WUTM_VERSION','test');
$options=[];$transients=[];$logged=true;$user=7;$allowed=false;
$routes=[];function did_action($hook){return 1;}function doing_action($hook){return false;}function register_rest_route($namespace,$route,$args){$GLOBALS['routes'][$namespace.$route]=$args;}function current_user_can($cap){return $GLOBALS['allowed'];}
function add_shortcode(...$a){}function add_action(...$a){}function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}function wp_parse_args($a,$b){return array_merge($b,$a);}
function is_user_logged_in(){return $GLOBALS['logged'];}function get_current_user_id(){return $GLOBALS['user'];}function wp_get_current_user(){return (object)['display_name'=>'Private member'];}
function wp_generate_password(...$a){return str_repeat('a',48);}function set_transient($k,$v,$t){$GLOBALS['transients'][$k]=$v;}function get_transient($k){return $GLOBALS['transients'][$k]??false;}
function admin_url($p){return 'https://fixture.test/wp-admin/'.$p;}function add_query_arg($a,$url){return $url.'?'.http_build_query($a);}function wp_enqueue_script(...$a){}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES);}function esc_attr($v){return esc_html($v);}function get_userdata($id){return (object)['ID'=>$id,'display_name'=>'Private member'];}
class WC_Member_Points_Rewards{static function instance(){return new self;}function wcmp_get_balance($id){return 123;}}
class WP_Error{public $code;function __construct($code,...$args){$this->code=$code;}}
class WP_REST_Response{public $data;function __construct($data){$this->data=$data;}function header(...$args){}}
class CardRequest{function get_param($k){return str_repeat('a',48);}}
require dirname(__DIR__).'/modules/member-loyalty/member-card.php';
function card_assert($ok,$message){if(!$ok)throw new RuntimeException($message);}
card_assert(isset($routes['wu-loyalty/v1/card']), 'Late module load registers card route after REST startup');
$permission=$routes['wu-loyalty/v1/card']['permission_callback'];card_assert(!$permission(),'Ordinary users cannot verify cards');$allowed=true;card_assert($permission(),'Authorized staff can verify cards');$allowed=false;
card_assert(WUTM_Loyalty_Card::render()==='','Off by default');
$options['wutm_loyalty_card']=['enabled'=>true];$logged=false;card_assert(strpos(WUTM_Loyalty_Card::render(),'請先登入')!==false,'Logged-out privacy');
$logged=true;$html=WUTM_Loyalty_Card::render();card_assert(strpos($html,'123')!==false,'Actual points service used');
card_assert(strpos($html,'card_token=')!==false && strpos($html,'user_id=')===false,'QR contains opaque token, no raw user ID');
$result=WUTM_Loyalty_Card::verify(new CardRequest);card_assert($result->data['member_id']===7,'Token maps to original member');
$transients['wutm_card_'.hash('sha256',str_repeat('a',48))]['expires']=time()-1;
card_assert(WUTM_Loyalty_Card::verify(new CardRequest) instanceof WP_Error,'Expired token rejected');
echo "Member card privacy checks passed.\n";
