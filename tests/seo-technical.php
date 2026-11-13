<?php
require __DIR__ . '/seo-core.php';
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
$xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.test/a/?a=1&amp;b=2</loc><lastmod>2026-01-01</lastmod></url></urlset>';
$result = Wumetax_SEO_Core_v120::inspect_xml( $xml );
check( ! Wumetax_SEO_Core_v120::inspect_xml( '' )['valid'], 'Empty response is reported instead of throwing' );
check( $result['valid'] && $result['count'] === 1 && $result['urls'][0] === 'https://example.test/a/?a=1&b=2', 'XML namespace, escaping and date validated' );
check( ! Wumetax_SEO_Core_v120::inspect_xml( str_replace( 'urlset', 'html', $xml ) )['valid'], 'HTML error page rejected' );
check( ! Wumetax_SEO_Core_v120::inspect_xml( str_replace( 'schemas/sitemap/0.9', 'wrong', $xml ) )['valid'], 'Wrong namespace rejected' );
check( ! Wumetax_SEO_Core_v120::inspect_xml( '<!DOCTYPE urlset [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . $xml )['valid'], 'DTD and external entities rejected' );
check( ! Wumetax_SEO_Core_v120::inspect_xml( str_replace( '2026-01-01', '2999-01-01', $xml ) )['valid'], 'Future lastmod rejected' );
check( ! Wumetax_SEO_Core_v120::inspect_xml( str_replace( '</urlset>', '<url><loc>https://example.test/a/?a=1&amp;b=2</loc></url></urlset>', $xml ) )['valid'], 'Duplicate URL detected' );
check( ! Wumetax_SEO_Core_v120::inspect_xml( "\xFF" . $xml )['valid'], 'Invalid UTF-8 rejected' );
check( ! Wumetax_SEO_Core_v120::inspect_xml( 'PHP warning before XML' . $xml )['valid'], 'Contaminated XML rejected' );
check( Wumetax_SEO_Core_v120::sitemap_style_url( 'test.xsl' ) === '', 'XML default independent of browser XSLT support' );
$options['wu_seo_sitemap_display'] = 'xsl';
check( Wumetax_SEO_Core_v120::sitemap_style_url( 'test.xsl' ) === 'test.xsl', 'XSL explicitly opt in' );
$options['active_plugins'] = [ 'wordpress-seo/wp-seo.php' ]; settings( [] );
check( Wumetax_SEO_Core_v120::sitemap_style_url( 'foreign.xsl' ) === 'foreign.xsl', 'Foreign sitemap styling left alone' );
check( Wumetax_SEO_Core_v120::filter_sitemap_stylesheet_content( '<foreign/>' ) === '<foreign/>', 'Foreign XSL content left alone' );
$options['active_plugins'] = []; settings( [] );
$settings = Wumetax_SEO_Core_v120::sanitize_settings( [ 'target_locale' => 'de_DE', 'target_region' => 'DE', 'organization_type' => 'none' ] );
check( $settings['target_locale'] === 'de_DE' && $settings['target_region'] === 'DE' && $settings['organization_type'] === 'none', 'Generic language and optional organization' );
check( Wumetax_SEO_Core_v120::diagnostic_request( 'https://example.test.attacker.invalid/' )['error'], 'Cross-origin URL rejected before transport' );
check( Wumetax_SEO_Core_v120::diagnostic_request( 'https://example.test:8443/' )['error'], 'Alternate port rejected' );
check( Wumetax_SEO_Core_v120::diagnostic_request( 'https://user:pass@example.test/' )['error'], 'Embedded credentials rejected' );
echo "SEO technical security checks passed.\n";

$r = WUTM_SEO_Sitemap_Review::evidence( [ 'status' => 200, 'url' => 'https://example.test/a/', 'chain' => [ ['status'=>301], ['status'=>200] ], 'body' => '<html><head><meta name="robots" content="noindex"><link rel="canonical" href="https://example.test/b/"></head></html>' ] );
check( count( $r['issues'] ) === 3 && strpos( $r['google'], '尚未驗證' ) !== false, 'Redirect, noindex and noncanonical evidence remain separate from Google' );
$r = WUTM_SEO_Sitemap_Review::evidence( [ 'status'=>403, 'challenge'=>true, 'body'=>'<html>challenge</html>' ] );
check( count( $r['issues'] ) === 2, 'Challenge never reported as normal indexable content' );
echo "Sitemap review evidence checks passed.\n";
