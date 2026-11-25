<?php
defined( 'ABSPATH' ) || exit;
$post_url = admin_url( 'admin-post.php' );
$refresh_url = self::indexnow_admin_url( $all ? ['indexnow_view'=>'all','indexnow_days'=>$days] : ['indexnow_days'=>$days] );
$time = static function ( $timestamp ) { return $timestamp ? wp_date( 'Y-m-d H:i:s', (int) $timestamp ) : '—'; };
$form_fields = static function () {
    wp_nonce_field( 'wu_seo_indexnow' );
    echo '<input type="hidden" name="action" value="wu_seo_indexnow">';
};
?>
<div class="wui-indexnow">
    <?php if ( $notice ) : ?><div role="status" class="notice notice-info inline"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
    <div class="wus-row">
        <h2>IndexNow</h2>
        <div class="wus-inline">
            <span class="wus-chip<?php echo $settings['enabled'] && $verified ? ' wus-indexed' : ''; ?>"><?php echo $settings['enabled'] ? ( $verified ? '自動通知已啟用' : '等待有效的金鑰驗證' ) : '自動通知未啟用'; ?></span>
            <a class="button" href="<?php echo esc_url( $refresh_url ); ?>">更新畫面</a>
        </div>
    </div>

    <details class="wutm-ui-panel wus-card wui-setup" <?php if ( $notice || ( $settings['enabled'] && !$verified ) ) echo 'open'; ?>>
        <summary>設定與測試</summary>
        <p class="wus-muted">啟用前請確認 Yoast、Rank Math 或 Cloudflare 是否已代送同一批網址，並指定一個整合負責提交。</p>
        <div class="wui-settings">
            <section>
                <h3><span class="dashicons dashicons-admin-network" aria-hidden="true"></span> 驗證檔</h3>
                <form method="post" action="<?php echo esc_url( $post_url ); ?>">
                    <?php $form_fields(); ?>
                    <label>IndexNow 金鑰<input class="regular-text" name="key" maxlength="128" pattern="[A-Za-z0-9-]{8,128}" value="<?php echo esc_attr( $settings['key'] ); ?>"></label>
                    <label>自訂驗證檔網址<input class="regular-text" type="url" name="location" maxlength="2048" placeholder="留白使用預設位置" value="<?php echo esc_attr( $settings['location'] ); ?>"></label>
                    <div class="wus-inline"><button class="button" name="operation" value="generate" formnovalidate><?php echo $location ? '更換金鑰' : '產生金鑰'; ?></button>
                    <button class="button" name="operation" value="save_key">儲存金鑰</button></div>
                </form>
                <form method="post" action="<?php echo esc_url( $post_url ); ?>">
                    <?php $form_fields(); ?>
                    <button class="button" name="operation" value="verify" <?php disabled( !$location ); ?>>驗證公開檔案</button>
                </form>
                <?php if ( $location ) : ?><p><a href="<?php echo esc_url( $location ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $location ); ?></a></p><?php endif; ?>
                <?php if ( $proof ) : ?><p><strong><?php echo esc_html( $verified ? '已驗證' : '驗證未通過' ); ?></strong> · HTTP <?php echo esc_html( $proof['http'] ?: '—' ); ?> · <?php echo esc_html( $time( $proof['time'] ) ); ?><br><?php echo esc_html( $proof['detail'] ); ?></p><?php endif; ?>
                <details><summary>驗證細節與手動放置</summary>
                    <?php foreach ( $proof['headers'] ?? [] as $header=>$value ) : ?><p><code><?php echo esc_html( $header . ': ' . $value ); ?></code></p><?php endforeach; ?>
                    <p>若驗證檔被快取、WAF 或重寫規則攔截，請在同一網站放置 UTF-8 的 <code>.txt</code> 檔，內容只填金鑰。檔案須匿名可讀、無轉址、BOM 或換行。使用子資料夾時，驗證範圍限於該資料夾下的網址。</p>
                </details>
            </section>
            <section>
                <h3><span class="dashicons dashicons-update" aria-hidden="true"></span> 自動通知</h3>
                <form method="post" action="<?php echo esc_url( $post_url ); ?>">
                    <?php $form_fields(); ?>
                    <p><label><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?>> 啟用自動通知及發布健檢</label></p>
                    <fieldset><legend>公開內容類型</legend>
                        <?php foreach ( self::indexnow_types() as $name=>$type ) : ?>
                        <label><input type="checkbox" name="types[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $settings['types'], true ) ); ?>> <?php echo esc_html( $type->labels->name ); ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <?php if ( $settings['enabled'] && ! wp_next_scheduled( self::INDEXNOW_CRON ) ) : ?><p role="alert">未找到背景排程。請重新儲存自動通知設定。</p><?php endif; ?>
                    <p><button class="button button-primary" name="operation" value="automation">儲存自動通知</button></p>
                </form>
            </section>
            <section>
                <h3><span class="dashicons dashicons-upload" aria-hidden="true"></span> 測試提交</h3>
                <form method="post" action="<?php echo esc_url( $post_url ); ?>">
                    <?php $form_fields(); ?>
                    <label>近期變更的公開網址<input class="regular-text" type="url" name="url" maxlength="2048" required></label>
                    <p><button class="button" name="operation" value="test" <?php disabled( !$settings['enabled'] || !$verified ); ?>>排入測試提交</button></p>
                </form>
                <p><a href="https://www.bing.com/webmasters/" target="_blank" rel="noopener">開啟 Bing Webmaster Tools</a></p>
            </section>
        </div>
    </details>

    <section class="wus-metrics" aria-label="IndexNow 提交狀態">
        <?php foreach ( [
            ['yes-alt','已送達',$counts['delivered']], ['clock','待處理',$counts['queued']],
            ['update','待驗證／重試',$counts['accepted'] + $counts['retry']],
            ['warning','需要處理',$counts['attention'] ?? 0],
        ] as [$icon,$name,$amount] ) : ?>
            <article class="wutm-ui-panel wus-card wus-kpi"><h2><span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span> <?php echo esc_html( $name ); ?></h2><strong><?php echo esc_html( number_format_i18n( $amount ) ); ?></strong></article>
        <?php endforeach; ?>
    </section>

    <div class="wus-primary">
        <section class="wutm-ui-panel wus-card">
            <div class="wus-row"><h2>每日提交網址數</h2><nav class="wui-ranges" aria-label="提交統計期間">
                <?php foreach ( [7,30] as $range ) : ?>
                    <a class="button" href="<?php echo esc_url( self::indexnow_admin_url( ['indexnow_days'=>$range] + ( $all ? ['indexnow_view'=>'all'] : [] ) ) ); ?>" <?php if ( $days === $range ) echo 'aria-current="true"'; ?>><?php echo (int) $range; ?> 天</a>
                <?php endforeach; ?>
            </nav></div>
            <?php if ( $chart && max( $chart ) > 0 ) : $peak = max( $chart ); ?>
                <div class="wui-chart-scroll" tabindex="0" role="region" aria-label="每日提交柱狀圖">
                    <ol class="wui-bars<?php echo $days === 30 ? ' wui-bars-long' : ''; ?>">
                    <?php foreach ( $chart as $date=>$count ) :
                        $short = substr( $date, 5 );
                        $count_text = $count === null ? '—' : (string) $count;
                        ?>
                        <li tabindex="0" aria-label="<?php echo esc_attr( $date . ( $count === null ? "，無資料" : "，$count_text 筆" ) ); ?>">
                            <span class="wui-count" aria-hidden="true"><?php echo esc_html( $count_text ); ?></span>
                            <span class="wui-track" aria-hidden="true"><i style="height:<?php echo (int) round( 100 * ( $count ?? 0 ) / $peak ); ?>%"></i></span>
                            <small aria-hidden="true"><?php echo esc_html( $short ); ?></small>
                        </li>
                    <?php endforeach; ?>
                    </ol>
                </div>
            <?php else : ?><p class="wus-empty" aria-label="此期間沒有提交紀錄">—</p><?php endif; ?>
            <small>以實際送出的要求計數，包含重試。收錄狀態請到 Bing 或 Google 查詢。</small>
        </section>
        <section class="wutm-ui-panel wus-card">
            <h2>發布健檢異常分布</h2>
            <?php if ( $issues ) : $largest = max( $issues ); ?>
                <ul class="wui-distribution">
                    <?php foreach ( $issues as $code=>$total ) : ?><li><span><?php echo esc_html( $health_labels[$code] ); ?></span><strong><?php echo (int) $total; ?></strong>
                        <span aria-hidden="true" class="wui-meter"><i style="width:<?php echo (int) round( 100 * $total / $largest ); ?>%"></i></span></li><?php endforeach; ?>
                </ul>
            <?php else : ?><p class="wus-empty" aria-label="沒有已記錄的異常">—</p><?php endif; ?>
        </section>
    </div>

    <section class="wutm-ui-panel wus-card">
        <div class="wus-row"><h2><?php echo $all ? '近期網址' : '需要留意的網址'; ?></h2>
            <a href="<?php echo esc_url( self::indexnow_admin_url( ['indexnow_days'=>$days] + ( $all ? [] : ['indexnow_view'=>'all'] ) ) ); ?>"><?php echo $all ? '只看需要留意的項目' : '查看全部狀態'; ?></a>
        </div>
        <?php if ( !$rows ) : ?><p class="wus-muted"><?php echo $all ? '尚無紀錄。' : '目前沒有需要留意的項目。'; ?></p><?php else : ?>
        <div class="wus-scroll wui-list" tabindex="0" role="region" aria-label="IndexNow 網址紀錄">
            <table class="widefat striped">
                <thead><tr><th scope="col">網址</th><th scope="col">變更類型</th><th scope="col">通知狀態</th><th scope="col">健檢結果</th><th scope="col">最後時間</th></tr></thead>
                <tbody><?php foreach ( $rows as $row ) :
                    $status = $states[$row['state']] ?? ['—','minus',''];
                    $health = json_decode( $row['health'], true ) ?: [];
                    $result = json_decode( $row['response'], true ) ?: [];
                    $page = $health['page'] ?? [];
                    $sitemap = $health['sitemap'] ?? [];
                    $abnormal = isset( $health_labels[$row['health_code']] );
                    ?>
                    <tr>
                        <th scope="row">
                            <a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['url'] ); ?></a>
                            <details><summary>詳情</summary>
                                <dl class="wui-evidence">
                                    <dt>IndexNow API</dt><dd><?php echo esc_html( ( ! empty( $result['http'] ) ? 'HTTP ' . $result['http'] . ' · ' : '' ) . ( $result['message'] ?? '—' ) ); ?>
                                        <?php if ( ! empty( $result['body'] ) ) : ?><pre><?php echo esc_html( $result['body'] ); ?></pre><?php endif; ?>
                                        <?php if ( ! empty( $result['time'] ) ) : ?><small><?php echo esc_html( $time( $result['time'] ) ); ?></small><?php endif; ?>
                                        <?php if ( $row['state'] === 'retry' ) : ?><p>下次嘗試 <?php echo esc_html( $time( $row['due'] ) ); ?></p><?php endif; ?>
                                    </dd>
                                    <dt>公開 HTTP</dt><dd><?php echo esc_html( ! empty( $page['status'] ) ? 'HTTP ' . $page['status'] : '—' ); ?> · <?php echo esc_html( $time( $page['checked_at'] ?? 0 ) ); ?>
                                        <br><?php echo esc_html( $page['final_url'] ?? '—' ); ?>
                                        <?php if ( !empty( $page['issues'] ) ) : ?><p><?php echo esc_html( implode( '；', $page['issues'] ) ); ?></p><?php endif; ?></dd>
                                    <dt>Canonical</dt><dd><?php echo esc_html( ( $page['canonical'] ?? '' ) ?: '—' ); ?></dd>
                                    <dt>頁面 robots</dt><dd><?php echo esc_html( implode( '；', $page['meta_robots'] ?? [] ) ?: '—' ); ?></dd>
                                    <dt>偵測到 noindex</dt><dd><?php echo esc_html( !$page || !empty( $page['error'] ) || !empty( $page['challenge'] ) ? '未能判定' : ( $page['noindex'] ? '有' : '未發現' ) ); ?></dd>
                                    <dt>X-Robots-Tag</dt><dd><?php echo esc_html( ( $page['header_robots'] ?? '' ) ?: '—' ); ?></dd>
                                    <dt>公開 Sitemap</dt><dd><?php echo esc_html( $sitemap['source'] ?? '—' ); ?> · <?php echo esc_html( $time( $sitemap['source_time'] ?? 0 ) ); ?>
                                        <?php if ( isset( $sitemap['http'] ) ) : ?> · HTTP <?php echo (int) $sitemap['http']; ?><?php endif; ?>
                                        <?php if ( ! empty( $sitemap['state'] ) ) : ?><br><?php echo esc_html( $sitemap['state'] === 'pending' ? '分批檢查中' : $sitemap['state'] ); ?><?php endif; ?>
                                        <?php if ( isset( $sitemap['offset'], $sitemap['children'] ) ) : ?><br>已查 <?php echo (int) $sitemap['offset']; ?>／<?php echo (int) $sitemap['children']; ?> 個子檔<?php endif; ?>
                                        <?php if ( ! empty( $sitemap['detail'] ) ) : ?><p><?php echo esc_html( $sitemap['detail'] ); ?></p><?php endif; ?>
                                    </dd>
                                </dl>
                                <a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener">查看頁面</a>
                                <?php if ( $settings['enabled'] ) : ?>
                                <form method="post" action="<?php echo esc_url( $post_url ); ?>">
                                    <?php $form_fields(); ?>
                                    <input type="hidden" name="row" value="<?php echo esc_attr( $row['url_hash'] ); ?>">
                                    <div class="wus-inline"><button class="button" name="operation" value="health">重跑健檢</button>
                                    <?php if ( in_array( $row['state'], ['failed','held','retry'], true ) ) : ?><button class="button" name="operation" value="retry">重試提交</button><?php endif; ?></div>
                                </form>
                                <?php endif; ?>
                            </details>
                        </th>
                        <td><?php echo esc_html( $kind_labels[$row['kind']] ?? '—' ); ?></td>
                        <td><span class="wus-chip <?php echo esc_attr( $status[2] ); ?>"><span class="dashicons dashicons-<?php echo esc_attr( $status[1] ); ?>" aria-hidden="true"></span> <?php echo esc_html( $status[0] ); ?></span></td>
                        <td><?php if ( $abnormal ) : ?><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php endif; ?><?php echo esc_html( $health['message'] ?? '待查' ); ?>
                            <?php if ( $abnormal && $row['health_due'] ) : ?><br><small>已安排複查</small><?php endif; ?></td>
                        <td><?php echo esc_html( $time( $row['updated'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table>
        </div><small>顯示最近 50 筆，已結束的工作保留 90 天。</small>
        <?php endif; ?>
    </section>
</div>
