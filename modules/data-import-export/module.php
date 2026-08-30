<?php
/**
 * Module: data-import-export
 * Unified CSV import/export for users and WooCommerce orders.
 */
defined('ABSPATH') || exit;

class WUTM_Data_Import_Export {
    private const SLUG = 'wu-data-import-export';
    private const CAP = 'manage_options';
    private const MAX_ROWS = 500;

    public function __construct() {
        add_action('admin_menu', [$this, 'menu'], 15);
        add_action('admin_post_wutm_die_export_users', [$this, 'export_users']);
        add_action('admin_post_wutm_die_import_users', [$this, 'import_users']);
        add_action('admin_post_wutm_die_export_orders', [$this, 'export_orders']);
        add_action('admin_post_wutm_die_import_orders', [$this, 'import_orders']);
        add_action('admin_post_wutm_die_export_password_hashes', [$this, 'export_password_hashes']);
        add_action('admin_post_wutm_die_restore_password_hashes', [$this, 'restore_password_hashes']);
    }

    public function menu(): void {
        add_submenu_page('wu-toolbox-modular', '資料匯入／匯出', '資料匯入／匯出', self::CAP, self::SLUG, [$this, 'page']);
    }

    private function guard(string $action): void {
        if (!current_user_can(self::CAP)) wp_die('權限不足');
        check_admin_referer($action);
    }

    private function url(): string { return admin_url('admin.php?page=' . self::SLUG); }

    private function redirect(array $result): void {
        set_transient('wutm_die_result_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect($this->url());
        exit;
    }

    public function page(): void {
        if (!current_user_can(self::CAP)) wp_die('權限不足');
        $result = get_transient('wutm_die_result_' . get_current_user_id());
        delete_transient('wutm_die_result_' . get_current_user_id());
        $wc_ready = class_exists('WooCommerce') && function_exists('wc_get_orders');
        ?>
        <div class="wrap wutm-module-wrap">
            <h1>資料匯入／匯出</h1>
            <p class="wutm-module-subtitle">集中管理使用者、WooCommerce 訂單與舊密碼雜湊。所有匯入均需管理員操作，不會在背景自動執行。</p>
            <?php if (is_array($result)): ?><div class="notice <?php echo !empty($result['error']) ? 'notice-error' : 'notice-success'; ?>"><p><strong><?php echo esc_html($result['message']); ?></strong></p><?php if (!empty($result['details'])): ?><ul><?php foreach ((array) $result['details'] as $detail): ?><li><?php echo esc_html($detail); ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>

            <section class="wutm-csv-tools">
                <h2>CSV 使用者匯入／匯出</h2>
                <p>匯出檔可再次匯入，且不含密碼。既有帳號預設只會略過；勾選後才更新基本資料。角色與密碼需另外明確勾選。</p>
                <div class="wutm-csv-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wutm_die_export_users'); ?><input type="hidden" name="action" value="wutm_die_export_users">
                        <label>匯出角色 <select name="role"><option value="">所有角色</option><?php foreach (get_editable_roles() as $key => $role): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html(translate_user_role($role['name'])); ?></option><?php endforeach; ?></select></label>
                        <label class="wutm-inline-choice"><input type="checkbox" name="include_meta" value="1" checked> 包含安全的自訂欄位</label>
                        <?php submit_button('下載使用者 CSV', 'secondary', 'submit', false); ?>
                    </form>
                    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wutm_die_import_users'); ?><input type="hidden" name="action" value="wutm_die_import_users">
                        <label>匯入 CSV <input type="file" name="csv" accept=".csv,text/csv" required></label>
                        <label class="wutm-inline-choice"><input type="checkbox" name="update_existing" value="1"> 更新既有帳號基本資料</label>
                        <label class="wutm-inline-choice"><input type="checkbox" name="update_credentials" value="1"> 同時更新既有帳號角色與明碼密碼</label>
                        <label class="wutm-inline-choice"><input type="checkbox" name="import_meta" value="1" checked> 匯入自訂欄位</label>
                        <?php submit_button('開始匯入', 'primary', 'submit', false); ?>
                    </form>
                </div>
                <p class="description">必要欄位：<code>user_login</code>、<code>user_email</code>。可選：<code>user_pass</code>、<code>first_name</code>、<code>last_name</code>、<code>nickname</code>、<code>display_name</code>、<code>user_url</code>、<code>description</code>、<code>role</code> 與安全自訂欄位。</p>
            </section>

            <section class="wutm-csv-tools">
                <h2>WooCommerce 訂單匯入／匯出</h2>
                <?php if (!$wc_ready): ?><div class="notice notice-warning inline"><p>尚未偵測到 WooCommerce；訂單功能目前不載入，不會影響網站運作。</p></div><?php else: ?>
                <p>支援依狀態與日期匯出，並匯出訂單地址、付款資料、總計與商品明細。匯入時會建立新訂單；既有訂單必須勾選更新才會修改。</p>
                <div class="wutm-csv-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wutm_die_export_orders'); ?><input type="hidden" name="action" value="wutm_die_export_orders">
                        <label>訂單狀態 <select name="status"><option value="">所有狀態</option><?php foreach (wc_get_order_statuses() as $status => $label): ?><option value="<?php echo esc_attr($status); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                        <label>起始日期 <input type="date" name="date_from"></label><label>結束日期 <input type="date" name="date_to"></label>
                        <?php submit_button('下載訂單 CSV', 'secondary', 'submit', false); ?>
                    </form>
                    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wutm_die_import_orders'); ?><input type="hidden" name="action" value="wutm_die_import_orders">
                        <label>匯入訂單 CSV <input type="file" name="csv" accept=".csv,text/csv" required></label>
                        <label class="wutm-inline-choice"><input type="checkbox" name="update_existing" value="1"> 更新相同 <code>order_id</code> 的既有訂單</label>
                        <?php submit_button('開始訂單匯入', 'primary', 'submit', false); ?>
                    </form>
                </div>
                <p class="description">訂單 CSV 由本工具匯出時可直接再匯入。<code>line_items</code> 使用 JSON 商品列（product_id、quantity、total）；找不到商品會略過該列並回報。</p>
                <?php endif; ?>
            </section>

            <section class="wutm-csv-tools">
                <h2>舊密碼雜湊還原</h2>
                <p>此工具只供舊網站搬遷。JSON 匯出包含登入名稱、電子郵件與雜湊；在新站必須先比對，再輸入 <code>RESTORE</code> 才會寫入既有帳號。請將 JSON 視為高度敏感資料。</p>
                <div class="wutm-csv-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wutm_die_export_password_hashes'); ?><input type="hidden" name="action" value="wutm_die_export_password_hashes">
                        <?php submit_button('下載密碼雜湊 JSON', 'secondary', 'submit', false); ?>
                    </form>
                    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wutm_die_restore_password_hashes'); ?><input type="hidden" name="action" value="wutm_die_restore_password_hashes">
                        <label>舊站 JSON <input type="file" name="hash_json" accept=".json,application/json" required></label>
                        <label class="wutm-inline-choice"><input type="radio" name="mode" value="preview" checked> 預覽比對，不修改</label>
                        <label class="wutm-inline-choice"><input type="radio" name="mode" value="restore"> 還原既有帳號雜湊</label>
                        <label>安全確認 <input type="text" name="confirm" placeholder="正式還原時輸入 RESTORE"></label>
                        <?php submit_button('執行密碼雜湊作業', 'secondary', 'submit', false); ?>
                    </form>
                </div>
            </section>
        </div><?php
    }

    private function read_csv_upload(string $field) {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return new WP_Error('file', '請選擇有效的 CSV 檔案。');
        if ((int)($file['size'] ?? 0) > 8 * MB_IN_BYTES) return new WP_Error('size', 'CSV 檔案不可超過 8 MB。');
        $raw = file_get_contents($file['tmp_name']);
        if ($raw === false) return new WP_Error('read', '無法讀取 CSV 檔案。');
        if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            if (!function_exists('mb_convert_encoding')) return new WP_Error('encoding', '請將 UTF-16 CSV 轉存為 UTF-8 後再匯入。');
            $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', str_starts_with($raw, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        $first = strtok($raw, "\r\n") ?: '';
        $scores = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")]; arsort($scores);
        $stream = fopen('php://temp', 'r+'); fwrite($stream, $raw); rewind($stream); $rows = [];
        while (($row = fgetcsv($stream, 0, (string)array_key_first($scores))) !== false) if ($row !== [null]) $rows[] = $row;
        fclose($stream);
        if (count($rows) < 2) return new WP_Error('rows', 'CSV 必須包含標題列與至少一筆資料。');
        if (count($rows) > self::MAX_ROWS + 1) return new WP_Error('limit', '一次最多匯入 ' . self::MAX_ROWS . ' 筆資料，請分批處理。');
        return $rows;
    }

    private function headers(array $headers): array {
        $aliases=['username'=>'user_login','login'=>'user_login','email'=>'user_email','email_address'=>'user_email','password'=>'user_pass','website'=>'user_url','url'=>'user_url','roles'=>'role','user_role'=>'role'];
        return array_map(static function($header) use($aliases) { $key=preg_replace('/[^a-z0-9_-]/','',preg_replace('/\s+/','_',strtolower(trim((string)$header)))); return $aliases[$key] ?? $key; }, $headers);
    }

    private function protected_meta(string $key): bool {
        return str_starts_with($key, '_') || in_array($key,['capabilities','user_level','session_tokens','application_passwords','dismissed_wp_pointers'],true) || str_ends_with($key,'capabilities') || str_ends_with($key,'user_level');
    }

    public function export_users(): void {
        $this->guard('wutm_die_export_users'); $args=['fields'=>'ID','number'=>-1,'orderby'=>'ID','order'=>'ASC'];
        $role=sanitize_key(wp_unslash($_POST['role']??'')); if($role && isset(get_editable_roles()[$role])) $args['role']=$role;
        $ids=get_users($args); $meta=[]; if(!empty($_POST['include_meta'])) { global $wpdb; $keys=$wpdb->get_col("SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key NOT LIKE '\\_%' ORDER BY meta_key LIMIT 40"); $meta=array_values(array_filter(array_map('sanitize_key',(array)$keys),fn($key)=>!$this->protected_meta($key))); }
        nocache_headers(); header('Content-Type:text/csv;charset=utf-8'); header('Content-Disposition:attachment; filename="wu-users-'.wp_date('Ymd-His').'.csv"'); $out=fopen('php://output','w'); fputs($out,"\xEF\xBB\xBF"); fputcsv($out,array_merge(['user_login','user_email','first_name','last_name','nickname','display_name','user_url','description','role'],$meta));
        foreach($ids as $id){$u=get_userdata((int)$id);if(!$u)continue;$row=[$u->user_login,$u->user_email,$u->first_name,$u->last_name,$u->nickname,$u->display_name,$u->user_url,$u->description,implode(',',(array)$u->roles)];foreach($meta as $key){$value=get_user_meta($u->ID,$key,true);$row[]=is_scalar($value)?$value:wp_json_encode($value,JSON_UNESCAPED_UNICODE);}fputcsv($out,$row);} fclose($out); exit;
    }

    public function import_users(): void {
        $this->guard('wutm_die_import_users'); $rows=$this->read_csv_upload('csv'); if(is_wp_error($rows))$this->redirect(['message'=>$rows->get_error_message(),'details'=>[],'error'=>true]);
        $headers=$this->headers(array_shift($rows)); if(!in_array('user_login',$headers,true)||!in_array('user_email',$headers,true))$this->redirect(['message'=>'CSV 缺少 user_login 或 user_email。','details'=>[],'error'=>true]);
        $create=0;$update=0;$skip=0;$details=[];$allow_update=!empty($_POST['update_existing']);$credentials=!empty($_POST['update_credentials']);$import_meta=!empty($_POST['import_meta']);
        foreach($rows as $n=>$row){$data=[];foreach($headers as $i=>$key)if($key!==''&&isset($row[$i]))$data[$key]=trim((string)$row[$i]);if(!array_filter($data))continue;$login=sanitize_user($data['user_login']??'',true);$email=sanitize_email($data['user_email']??'');$existing=$login?get_user_by('login',$login):false;if(!$existing&&$email)$existing=get_user_by('email',$email);
            if($existing){if(!$allow_update){$skip++;if(count($details)<8)$details[]='第 '.($n+2).' 列：帳號已存在。';continue;}$payload=['ID'=>$existing->ID];foreach(['user_email','first_name','last_name','nickname','display_name','user_url','description'] as $field)if(isset($data[$field]))$payload[$field]=$field==='user_email'?sanitize_email($data[$field]):($field==='user_url'?esc_url_raw($data[$field]):($field==='description'?sanitize_textarea_field($data[$field]):sanitize_text_field($data[$field])));if($credentials&&!empty($data['user_pass']))$payload['user_pass']=$data['user_pass'];$id=wp_update_user($payload);if(is_wp_error($id)){$skip++;if(count($details)<8)$details[]='第 '.($n+2).' 列：'.$id->get_error_message();continue;}if($credentials)$this->set_role($existing->ID,$data['role']??'');$this->save_user_meta($existing->ID,$data,$import_meta);$update++;continue;}
            if(!$login||!validate_username($login)||!$email||!is_email($email)){$skip++;if(count($details)<8)$details[]='第 '.($n+2).' 列：使用者名稱或電子郵件不正確。';continue;}$id=wp_insert_user(['user_login'=>$login,'user_email'=>$email,'user_pass'=>!empty($data['user_pass'])?$data['user_pass']:wp_generate_password(24,true,true),'first_name'=>sanitize_text_field($data['first_name']??''),'last_name'=>sanitize_text_field($data['last_name']??''),'display_name'=>sanitize_text_field($data['display_name']??'')]);if(is_wp_error($id)){$skip++;if(count($details)<8)$details[]='第 '.($n+2).' 列：'.$id->get_error_message();continue;}$this->set_role((int)$id,$data['role']??'');$this->save_user_meta((int)$id,$data,$import_meta);$create++;
        } $this->redirect(['message'=>"使用者匯入完成：新增 {$create}、更新 {$update}、略過 {$skip}。",'details'=>$details,'error'=>false]);
    }

    private function set_role(int $id,string $value):void{$role=sanitize_key(trim(explode(',',$value)[0]??''));if($role&&isset(get_editable_roles()[$role]))(new WP_User($id))->set_role($role);}
    private function save_user_meta(int $id,array $data,bool $enabled):void{if(!$enabled)return;$known=['user_login','user_email','user_pass','first_name','last_name','nickname','display_name','user_url','description','role'];foreach($data as $key=>$value){$key=sanitize_key($key);if($key&&!in_array($key,$known,true)&&!$this->protected_meta($key))update_user_meta($id,$key,sanitize_textarea_field($value));}}

    public function export_orders(): void {
        $this->guard('wutm_die_export_orders'); if(!function_exists('wc_get_orders'))wp_die('尚未啟用 WooCommerce。');$args=['limit'=>-1,'return'=>'objects','orderby'=>'date','order'=>'ASC'];$status=sanitize_key(wp_unslash($_POST['status']??''));if($status)$args['status']=$status;$from=sanitize_text_field(wp_unslash($_POST['date_from']??''));$to=sanitize_text_field(wp_unslash($_POST['date_to']??''));if($from)$args['date_created']='>='.$from;if($to)$args['date_created']=($from?'>='.$from.'...':'<=') . $to;
        $orders=wc_get_orders($args);nocache_headers();header('Content-Type:text/csv;charset=utf-8');header('Content-Disposition:attachment; filename="wu-orders-'.wp_date('Ymd-His').'.csv"');$out=fopen('php://output','w');fputs($out,"\xEF\xBB\xBF");fputcsv($out,['order_id','status','currency','date_created','customer_id','billing_first_name','billing_last_name','billing_email','billing_phone','billing_address_1','billing_address_2','billing_city','billing_state','billing_postcode','billing_country','shipping_first_name','shipping_last_name','shipping_address_1','shipping_address_2','shipping_city','shipping_state','shipping_postcode','shipping_country','payment_method','payment_method_title','transaction_id','customer_note','discount_total','shipping_total','total','line_items']);
        foreach($orders as $o){$items=[];foreach($o->get_items('line_item') as $item){$items[]=['product_id'=>$item->get_product_id(),'variation_id'=>$item->get_variation_id(),'name'=>$item->get_name(),'quantity'=>$item->get_quantity(),'total'=>$item->get_total()];}$b=$o->get_address('billing');$s=$o->get_address('shipping');fputcsv($out,[$o->get_id(),$o->get_status(),$o->get_currency(),$o->get_date_created()?$o->get_date_created()->date('Y-m-d H:i:s'):'',$o->get_customer_id(),$b['first_name'],$b['last_name'],$b['email'],$b['phone'],$b['address_1'],$b['address_2'],$b['city'],$b['state'],$b['postcode'],$b['country'],$s['first_name'],$s['last_name'],$s['address_1'],$s['address_2'],$s['city'],$s['state'],$s['postcode'],$s['country'],$o->get_payment_method(),$o->get_payment_method_title(),$o->get_transaction_id(),$o->get_customer_note(),$o->get_discount_total(),$o->get_shipping_total(),$o->get_total(),wp_json_encode($items,JSON_UNESCAPED_UNICODE)]);}fclose($out);exit;
    }

    public function import_orders(): void {
        $this->guard('wutm_die_import_orders');if(!function_exists('wc_create_order'))$this->redirect(['message'=>'尚未啟用 WooCommerce。','details'=>[],'error'=>true]);$rows=$this->read_csv_upload('csv');if(is_wp_error($rows))$this->redirect(['message'=>$rows->get_error_message(),'details'=>[],'error'=>true]);$headers=$this->headers(array_shift($rows));$create=0;$update=0;$skip=0;$details=[];$allow_update=!empty($_POST['update_existing']);
        foreach($rows as $n=>$row){$d=[];foreach($headers as $i=>$key)if($key!==''&&isset($row[$i]))$d[$key]=trim((string)$row[$i]);if(!array_filter($d))continue;$existing=!empty($d['order_id'])?wc_get_order((int)$d['order_id']):false;if($existing&&!$allow_update){$skip++;if(count($details)<8)$details[]='第 '.($n+2).' 列：訂單已存在。';continue;}$order=$existing?:wc_create_order(['customer_id'=>absint($d['customer_id']??0)]);if(is_wp_error($order)){$skip++;if(count($details)<8)$details[]='第 '.($n+2).' 列：'.$order->get_error_message();continue;}
            $billing=[];$shipping=[];foreach(['first_name','last_name','email','phone','address_1','address_2','city','state','postcode','country'] as $key)if(isset($d['billing_'.$key]))$billing[$key]=sanitize_text_field($d['billing_'.$key]);foreach(['first_name','last_name','address_1','address_2','city','state','postcode','country'] as $key)if(isset($d['shipping_'.$key]))$shipping[$key]=sanitize_text_field($d['shipping_'.$key]);$order->set_address($billing,'billing');$order->set_address($shipping,'shipping');foreach(['currency','payment_method','payment_method_title','transaction_id','customer_note'] as $key)if(isset($d[$key]))$order->{'set_'.$key}(sanitize_text_field($d[$key]));$items=json_decode($d['line_items']??'[]',true);if(!$existing&&is_array($items)){foreach($items as $item){$product=wc_get_product(absint($item['variation_id']??0)?:absint($item['product_id']??0));if(!$product)continue;$item_id=$order->add_product($product,max(1,absint($item['quantity']??1)));if($item_id&&isset($item['total'])){$line=$order->get_item($item_id);$line->set_total((float)$item['total']);$line->save();}}}$order->calculate_totals(false);$order->save();if(!empty($d['status'])){$status=str_replace('wc-','',sanitize_key($d['status']));if(isset(wc_get_order_statuses()['wc-'.$status]))$order->update_status($status,'由資料匯入工具設定。',true);}if($existing)$update++;else $create++;
        }$this->redirect(['message'=>"訂單匯入完成：新增 {$create}、更新 {$update}、略過 {$skip}。",'details'=>$details,'error'=>false]);
    }

    public function export_password_hashes(): void {
        $this->guard('wutm_die_export_password_hashes');global $wpdb;$users=$wpdb->get_results("SELECT ID,user_login,user_email,user_pass FROM {$wpdb->users} WHERE user_pass <> '' ORDER BY ID ASC",ARRAY_A);$json=wp_json_encode(['generated_at'=>current_time('mysql'),'site_url'=>home_url(),'users'=>$users],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);nocache_headers();header('Content-Type:application/json;charset=utf-8');header('Content-Disposition:attachment; filename="wu-password-hashes-'.wp_date('Ymd-His').'.json"');echo $json;exit;
    }

    public function restore_password_hashes(): void {
        $this->guard('wutm_die_restore_password_hashes');$file=$_FILES['hash_json']??null;if(!is_array($file)||empty($file['tmp_name'])||!is_uploaded_file($file['tmp_name']))$this->redirect(['message'=>'請選擇有效的 JSON 檔案。','details'=>[],'error'=>true]);$data=json_decode((string)file_get_contents($file['tmp_name']),true);if(!is_array($data)||!is_array($data['users']??null))$this->redirect(['message'=>'JSON 格式不正確。','details'=>[],'error'=>true]);$restore=(($_POST['mode']??'preview')==='restore'&&($_POST['confirm']??'')==='RESTORE');$matched=0;$changed=0;$details=[];global $wpdb;foreach(array_slice($data['users'],0,5000) as $row){$email=sanitize_email(strtolower(trim((string)($row['user_email']??''))));$login=sanitize_user((string)($row['user_login']??''),true);$target=$email?get_user_by('email',$email):false;if(!$target&&$login)$target=get_user_by('login',$login);if(!$target||empty($row['user_pass']))continue;$matched++;if($restore&&!hash_equals((string)$target->user_pass,(string)$row['user_pass'])){$ok=$wpdb->update($wpdb->users,['user_pass'=>(string)$row['user_pass']],['ID'=>$target->ID],['%s'],['%d']);if($ok!==false){clean_user_cache($target->ID);$changed++;}elseif(count($details)<8)$details[]='無法更新 '.$target->user_login;}}$this->redirect(['message'=>$restore?"密碼雜湊還原完成：比對 {$matched}、更新 {$changed}。":"密碼雜湊預覽完成：可比對 {$matched} 位既有帳號；尚未修改資料。",'details'=>$details,'error'=>false]);
    }
}
new WUTM_Data_Import_Export();
