<?php
if ( !defined( 'WP_CLI' ) || getenv( 'WUTM_MEDIA_INTEGRATION' ) !== '1' || DB_NAME !== 'wci_fixture' ) throw new RuntimeException( 'Disposable fixture only' );
require_once dirname(__DIR__) . '/modules/seo-core/module.php';

function seo421_assert( $ok, $reason ) { if (!$ok) throw new RuntimeException( $reason ); }
function seo421_call( $name, ...$args ) { return ( new ReflectionMethod( Wumetax_SEO_Core_v120::class, $name ) )->invoke( null,...$args ); }

$options = [];
foreach ( ['wu_seo_google','wpseo_titles','rank-math-options-general','show_on_front','page_for_posts','blog_public','wu_seo_dashboard_local_sitemap'] as $name ) $options[$name] = get_option( $name );
$saved_query = $GLOBALS['wp_query'];
$saved_cron = get_option( 'cron' );
$ids = $jobs = $calls = [];
$api_mode = 'ok';
$sub = home_url( '/wp-sitemap-posts-page-1.xml' );
$root = Wumetax_SEO_Core_v120::sitemap_url();
$google = 'https://www.googleapis.com/webmasters/v3/sites/';
$mock = static function ( $pre,$args,$url ) use ( &$calls,&$ids,&$api_mode,$sub,$root,$google ) {
    $calls[] = [ $url,$args ];
    $body = [];
    $code = 200;
    if ( $url === 'https://oauth2.googleapis.com/token' ) {
        $body = ['access_token'=>'fixture-access','expires_in'=>900];
    } elseif ( str_starts_with( $url, $google ) && str_ends_with( $url, '/searchAnalytics/query' ) ) {
        $request = json_decode( $args['body'],true );
        if ( $api_mode === 'auth' ) { $code = 401; }
        elseif ( $api_mode === 'quota' ) { $code = 429; }
        elseif ( $api_mode !== 'empty' ) {
            $metric = ['impressions'=>800,'clicks'=>64,'ctr'=>0.08,'position'=>5.2];
            $dimensions = $request['dimensions'];
            $rows = [$metric];
            if ($dimensions === ['date']) $rows = [['keys'=>[$request['startDate']]] + $metric];
            if ($dimensions === ['query']) $rows = [['keys'=>['高雄網頁設計'],'impressions'=>101] + $metric];
            if ($dimensions === ['page']) $rows = [
                ['keys'=>[home_url('/')],'impressions'=>81,'clicks'=>12] + $metric,
                ['keys'=>[get_permalink($ids[0])],'impressions'=>29,'clicks'=>2] + $metric,
            ];
            $body = ['rows'=>$rows];
        }
    } elseif ( str_contains( $url,'/urlInspection/' ) ) {
        $body = ['inspectionResult'=>['indexStatusResult'=>['verdict'=>'NEUTRAL','coverageState'=>'Crawled - currently not indexed','userCanonical'=>get_permalink($ids[0]),'sitemap'=>[$sub]]]];
    } elseif ( str_starts_with( $url,$google ) && str_contains( $url,'/sitemaps' ) ) {
        $body = ['sitemap'=>[['path'=>str_contains($url,'sitemapIndex=') ? $sub : $root,'lastDownloaded'=>'2026-11-15T06:00:00Z','errors'=>'0','warnings'=>'1']]];
    } elseif ( $url === $root || $url === $sub ) {
        $xml = $url === $root
            ? '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>'.esc_xml($sub).'</loc></sitemap></sitemapindex>'
            : '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>'.esc_xml(get_permalink($ids[0])).'</loc></url></urlset>';
        return ['headers'=>['content-type'=>'application/xml'],'body'=>$xml,'response'=>['code'=>200]];
    } else {
        return $pre;
    }
    return ['headers'=>['content-type'=>'application/json'],'body'=>wp_json_encode($body),'response'=>['code'=>$code]];
};
add_filter('pre_http_request',$mock,99,3);
try {
    update_option( 'blog_public',1 );
    $ids[] = wp_insert_post( ['post_title'=>'SEO dashboard test','post_type'=>'page','post_status'=>'draft'] );
    wp_update_post( ['ID'=>$ids[0],'post_status'=>'publish'] );
    seo421_assert( !$calls && wp_next_scheduled('wu_seo_publication_check',[$ids[0]]), 'Publish only enqueues the sitemap check' );
    Wumetax_SEO_Core_v120::dashboard_check_publication( $ids[0] );
    $publication = get_post_meta( $ids[0],'_wu_seo_publication_check',true );
    seo421_assert( ($publication['child'] ?? '') === $sub, 'Background XML checks the native child URL' );
    update_option( 'show_on_front','page' );
    update_option( 'page_for_posts',$ids[0] );
    update_post_meta( $ids[0], '_wu_seo_title','Manual blog title' );
    update_post_meta( $ids[0], '_wu_seo_description','Manual blog description' );
    update_post_meta( $ids[0], '_wu_seo_canonical',home_url('/official-blog/') );
    update_post_meta( $ids[0], '_wu_seo_noindex',1 );
    $GLOBALS['wp_query'] = new WP_Query();
    $GLOBALS['wp_query']->is_home = true;
    seo421_assert( seo421_call('seo_title') === 'Manual blog title' && seo421_call('seo_description') === 'Manual blog description' &&
        !empty(Wumetax_SEO_Core_v120::filter_wp_robots([])['noindex']) && seo421_call('canonical_url') === home_url('/official-blog/'), 'Posts page uses its actual SEO metadata' );
    $GLOBALS['wp_query']->set('paged',2);
    seo421_assert( seo421_call('canonical_url') === get_pagenum_link(2,false) && seo421_call('canonical_url') !== home_url('/official-blog/'), 'Paged posts page remains self-canonical' );
    $GLOBALS['wp_query'] = $saved_query;

    update_option('wpseo_titles',['company_name'=>'Example']);
    update_post_meta($ids[0],'_yoast_wpseo_title','Yoast title');
    update_post_meta($ids[0],'_yoast_wpseo_canonical',home_url('/yoast-blog/'));
    update_post_meta($ids[0],'_yoast_wpseo_meta-robots-noindex','2');
    $preview=seo421_call('legacy_preview','yoast',1);
    seo421_assert( count($preview['rows']['canonical']) && count($preview['rows']['noindex']), 'Sensitive fields have independent previews' );
    $preview['rows']['normal'] = array_values(array_filter($preview['rows']['normal'],static function($row) use ($ids){return (int)$row['id']===$ids[0];}));
    seo421_call('legacy_apply',$preview,[]);
    seo421_assert(get_post_meta($ids[0],'_wu_seo_title',true)==='Manual blog title' && get_post_meta($ids[0],'_wu_seo_noindex',true)==='1','Default preserves populated fields');
    seo421_call('legacy_apply',$preview,['canonical'=>true,'overwrite_canonical'=>true]);
    seo421_assert(get_post_meta($ids[0],'_wu_seo_canonical',true)===home_url('/yoast-blog/') && get_post_meta($ids[0],'_wu_seo_noindex',true)==='1','Canonical confirmation cannot change noindex');
    update_option('rank-math-options-general',[]);
    update_post_meta($ids[0],'rank_math_robots',['index']);
    $preview=seo421_call('legacy_preview','rank-math',1);
    seo421_call('legacy_apply',$preview,['noindex'=>true,'overwrite_noindex'=>true]);
    seo421_assert(get_post_meta($ids[0],'_wu_seo_noindex',true)==='0','Explicit reviewed Rank Math indexing field imports');

    update_option('wu_seo_google',['property'=>'sc-domain:example.test','client_id'=>'fixture-client','secret'=>seo421_call('google_secret','fixture-secret'),
        'refresh'=>seo421_call('google_secret','fixture-refresh')],false);
    $scope=seo421_call('google_scope');
    $range=Wumetax_SEO_Core_v120::dashboard_range(['start'=>'2026-03-07','end'=>'2026-03-11','compare'=>true,'country'=>'TWN','device'=>'mobile']);
    seo421_assert($range['prior_start']==='2026-03-02' && $range['prior_end']==='2026-03-06','Prior period uses calendar days across DST');
    $params=['range'=>$range,'type'=>'','value'=>''];
    $before=count($calls);
    $job=seo421_call('dashboard_queue',$scope,'performance',$params,['total','daily','queries','pages','prior_total']);
    $jobs[]=$job;
    seo421_assert(is_string($job) && count($calls)===$before,'Dashboard only enqueues network calls');
    Wumetax_SEO_Core_v120::dashboard_worker($job);
    Wumetax_SEO_Core_v120::dashboard_worker($job);
    $data=get_transient('wu_seo_job_'.$job);
    seo421_assert($data['state']==='ready' && $data['data']['total'][0]['impressions']===800.0 &&
        $data['data']['queries'][0]['impressions']===101.0,'Site aggregate is independent of the top query list');
    $params=['range'=>$range,'type'=>'query','value'=>'高雄網頁設計'];
    $job=seo421_call('dashboard_queue',$scope,'performance',$params,['pages']);
    $jobs[]=$job;
    Wumetax_SEO_Core_v120::dashboard_worker($job);
    $rows=get_transient('wu_seo_job_'.$job)['data']['pages'];
    seo421_assert(array_column($rows,'impressions')===[81.0,29.0] && $rows[0]['key']===home_url('/'),'Tracked query exposes both real response rows without combining page impressions');
    $recent=json_decode(end($calls)[1]['body'],true);
    seo421_assert($recent['aggregationType']==='auto' && in_array(['dimension'=>'query','operator'=>'equals','expression'=>'高雄網頁設計'],$recent['dimensionFilterGroups'][0]['filters'],true),'Drilldown keeps country/device and exact query');
    $job=seo421_call('dashboard_queue',$scope,'inspect',$ids,$ids,true);
    $jobs[]=$job;
    Wumetax_SEO_Core_v120::dashboard_worker($job);
    $record=get_post_meta($ids[0],'_wu_seo_google_inspection',true)['record'];
    seo421_assert($record['state']==='excluded' && $record['detail']['sitemap']===[$sub],'Google verdict and returned sitemap are retained independently');
    $job=seo421_call('dashboard_queue',$scope,'sitemaps',[],['submitted','children'],true);
    $jobs[]=$job;
    Wumetax_SEO_Core_v120::dashboard_worker($job);
    $data=get_transient('wu_seo_job_'.$job)['data'];
    seo421_assert($data['submitted'][0]['path']===$root && $data['children'][0]['path']===$sub,'Google sitemap root and child responses remain distinct');

    foreach (['empty','auth','quota'] as $mode) {
        $api_mode=$mode;
        $params=['range'=>$range,'type'=>'query','value'=>'fixture-'.$mode];
        $job=seo421_call('dashboard_queue',$scope,'performance',$params,['total']);
        $jobs[]=$job;
        Wumetax_SEO_Core_v120::dashboard_worker($job);
        $result=get_transient('wu_seo_job_'.$job);
        $passed=$mode==='empty' ? ($result['state']==='ready' && $result['data']['total']===[]) :
            ($mode==='auth' ? ($result['state']==='failed' && $result['errors']['total']['code']==='auth') : ($result['state']==='pending' && $result['tries']===1));
        seo421_assert($passed,$mode.' has an independent status');
    }
    echo "SEO dashboard native checks passed: aggregate, comparisons, keyword drilldown, inspection, sitemap, publish, import, auth and quota.\n";
} finally {
    remove_filter('pre_http_request',$mock,99);
    foreach ($ids as $id) wp_delete_post($id,true);
    foreach ($jobs as $id) if (is_string($id)) delete_transient('wu_seo_job_'.$id);
    foreach (['wu_seo_google_cooldown',Wumetax_SEO_Core_v120::DASHBOARD_QUEUE,'wu_seo_token_'.substr($scope ?? '',0,40),'wu_seo_http_'.md5($root),'wu_seo_http_'.md5($sub)] as $key) delete_transient($key);
    foreach ($options as $key=>$value) { if (false===$value) delete_option($key); else update_option($key,$value); }
    update_option('cron',$saved_cron);
    $GLOBALS['wp_query']=$saved_query;
}
