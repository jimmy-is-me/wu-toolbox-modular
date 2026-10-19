<?php
/** CLI-only tree persistence and native media layout fixtures. */
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__ . '/'); define('WUTM_URL', '/'); define('WUTM_VERSION', 'test');
$capable = $nonce = true; $meta = []; $terms = [];
$media_ui = true;
function wp_script_is(...$args) { return $GLOBALS['media_ui']; }
function is_admin() { return true; }
foreach ([[1,0,'主分類甲'],[2,0,'主分類乙'],[3,1,'子分類咖啡'],[4,1,'子分類茶'],[5,3,'深層分類']] as $data) {
    $terms[$data[0]] = (object) ['term_id'=>$data[0], 'parent'=>$data[1], 'name'=>$data[2], 'count'=>$data[0]];
}
function add_action(...$args) {} function add_filter(...$args) {}
function current_user_can(...$args) { return $GLOBALS['capable']; }
function check_ajax_referer(...$args) { if (!$GLOBALS['nonce']) throw new RuntimeException('nonce'); }
function wp_unslash($v) { return $v; } function sanitize_key($v) { return preg_replace('/[^a-z0-9_-]/','',strtolower($v)); }
function absint($v) { return abs((int)$v); } function is_wp_error($v) { return false; }
function get_term($id,$tax) { return $GLOBALS['terms'][$id] ?? false; }
function get_term_meta($id,$key,$single=true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_term_meta($id,$key,$v) { $GLOBALS['meta'][$id][$key] = (string)$v; return true; }
function get_taxonomies(...$args) { return ['category'=>(object)['cap'=>(object)['manage_terms'=>'manage_categories']]]; }
function esc_attr($v) { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); } function esc_html($v) { return esc_attr($v); }
function admin_url($v) { return '/wp-admin/'.$v; } function wp_create_nonce(...$args) { return 'fixture'; }
function wp_json_encode($v,$flags=0) { return json_encode($v,$flags); }
class JSONResult extends RuntimeException { public $success; function __construct($success) { $this->success=$success; } }
function wp_send_json_success(...$args) { throw new JSONResult(true); } function wp_send_json_error(...$args) { throw new JSONResult(false); }
require dirname(__DIR__).'/modules/content-ordering/module.php';
require dirname(__DIR__).'/modules/media-library-manager/module.php';
$mode = $argv[1] ?? 'policy';
if ($mode === 'tree') { WUTM_Content_Ordering::render_tree(array_values($terms)); exit; }
if ($mode === 'media-css') { wumf_admin_css(); exit; }
if ($mode === 'media-js') { wumf_admin_js(); exit; }
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$media_ui=false; ob_start(); wumf_admin_css(); wumf_admin_js(); check(ob_get_clean()==='', 'No media observers/scripts/styles on unrelated admin screens'); $media_ui=true;
ob_start(); wumf_admin_css(); $late_css=ob_get_clean(); check(strpos($late_css,'wumf-admin-css')!==false,'Media styles can be printed after late media enqueue');
ob_start(); wumf_admin_css(); check(ob_get_clean()==='', 'Head/footer fallback emits media CSS once only');
function save_result($ids,$parent=0) {
    $_POST=['taxonomy'=>'category','parent'=>$parent,'ids'=>$ids];
    try { WUTM_Content_Ordering::save_terms(); } catch (JSONResult $result) { return $result->success; }
    return false;
}
check(save_result([2,1]),'Sibling order saved');
check($meta[2]['wutm_content_order_position']==='0','First position persisted');
check(save_result([4,3],1),'Nested sibling order saved');
$before=$meta; check(!save_result([1,3]),'Mixed parent rejected'); check($meta===$before,'No writes before complete validation');
$capable=false; check(!save_result([1,2]),'Capability enforced'); $capable=true;
$nonce=false; try { save_result([1,2]); throw new LogicException('Missing nonce protection'); } catch (RuntimeException $e) { check($e->getMessage()==='nonce','Nonce enforced'); }
ob_start(); WUTM_Content_Ordering::render_tree(array_values($terms)); $html=ob_get_clean();
check(substr_count($html,'data-id=')===5,'Every term appears once'); check(strpos($html,'data-parent="3"')!==false,'Deep tree preserved');
echo "Admin enhancements policy passed: tree hierarchy, sibling persistence, nonce/capability and no reparenting.\n";
