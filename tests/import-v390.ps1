$root390=Split-Path $PSScriptRoot -Parent
$form390=[IO.File]::ReadAllText('C:/Users/jimin/.codex/attachments/80e244ed-8081-48d9-a76e-3ca25ba84670/貼上的文字.txt')
$order390=[IO.File]::ReadAllText('C:/Users/jimin/.codex/attachments/cd428311-2bdc-40bc-9c0a-c19d28cdee24/貼上的文字.txt')
# Mechanical imports retain all source sections and legacy database identifiers.
$form390=$form390.Replace('khmf_', 'wutm_cf_').Replace('KHMF_OPT','WUTM_CF_OPT')
foreach($key390 in @('fields','recipient','subject','captcha','entry','data','ip')) {
  if($key390 -eq 'fields') {$form390=$form390.Replace("define('WUTM_CF_OPT', 'wutm_cf_fields')","define('WUTM_CF_OPT', 'khmf_fields')");continue}
  $form390=$form390.Replace("'wutm_cf_$key390'","'khmf_$key390'").Replace("'_wutm_cf_$key390'","'_khmf_$key390'")
}
# Hook callback/type identifiers require the renamed functions, not option keys.
$form390=$form390.Replace("'sanitize_callback' => 'khmf_captcha'","'sanitize_callback' => 'absint'")
$form390=$form390.Replace("add_submenu_page('edit.php?post_type=khmf_entry'", "add_submenu_page('wu-toolbox-modular'").Replace("'khmf-settings'", "'wu-contact-form'")
$form390=$form390.Replace("'menu_icon' => 'dashicons-email-alt', 'menu_position' => 26,", "'show_in_menu' => 'wu-toolbox-modular',")
$form390=$form390.Replace("'capability_type' => 'post',", "'capability_type' => 'post', 'capabilities' => ['edit_posts'=>'manage_options','edit_others_posts'=>'manage_options','publish_posts'=>'manage_options','read_private_posts'=>'manage_options','delete_posts'=>'manage_options','delete_others_posts'=>'manage_options','delete_private_posts'=>'manage_options','delete_published_posts'=>'manage_options','edit_private_posts'=>'manage_options','edit_published_posts'=>'manage_options','create_posts'=>'do_not_allow'],")
$form390=$form390.Replace("'capabilities' => ['create_posts' => 'do_not_allow'],",'')
$form390=$form390.Replace("function wutm_cf_settings_page() {", "function wutm_cf_settings_page() { if (!current_user_can('manage_options')) return; wutm_admin_page_open('聯絡表單', '短碼：[wu_contact_form]；原短碼 [kh_mini_form_x7] 仍可使用。');")
$form390=$form390.Replace('<?php }', '<?php wutm_admin_page_close(); }')
# Retain source shortcode as an alias; add the WU name after registration.
$form390 += "`nadd_shortcode('wu_contact_form', `$GLOBALS['shortcode_tags']['kh_mini_form_x7']);`n"
$order390=$order390.Replace('khto_', 'wutm_to_').Replace("'_wutm_to_test'", "'_khto_test'").Replace("'created_via' => 'khto'", "'created_via' => 'khto'")
$order390=$order390.Replace("add_submenu_page('woocommerce'", "add_submenu_page('wu-toolbox-modular'").Replace("'khto', 'wutm_to_page'", "'wu-test-orders', 'wutm_to_page'").Replace('admin.php?page=khto','admin.php?page=wu-test-orders')
$order390=$order390.Replace("function wutm_to_page() {", "function wutm_to_page() {").Replace("    `$u = wp_get_current_user();", "    wutm_admin_page_open('測試訂單產生器', '會建立真實資料庫訂單，請優先在測試站使用。預設不寄信、不扣庫存。');`n    `$u = wp_get_current_user();")
$order390=$order390.Replace("<?php`r`n}","<?php wutm_admin_page_close();`r`n}")
foreach($entry390 in @(@('contact-form',$form390),@('test-orders',$order390))) {
  $dir390=Join-Path $root390 ('modules/'+$entry390[0]);[IO.Directory]::CreateDirectory($dir390)|Out-Null
  [IO.File]::WriteAllText((Join-Path $dir390 'source.php'),$entry390[1].Replace("`r`n","`n"),[Text.UTF8Encoding]::new($false))
}
