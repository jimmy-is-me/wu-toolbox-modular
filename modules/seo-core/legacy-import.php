<?php
defined( 'ABSPATH' ) || exit;

trait WUTM_SEO_Legacy_Import {
		/** Return installed legacy SEO sources that can safely be imported on demand. */
		private static function legacy_import_sources() {
			return [
				'yoast' => [
					'label'     => 'Yoast SEO',
					'available' => defined( 'WPSEO_VERSION' ) || is_array( get_option( 'wpseo_titles', false ) ),
				],
				'rank-math' => [
					'label'     => 'Rank Math',
					'available' => defined( 'RANK_MATH_VERSION' ) || is_array( get_option( 'rank-math-options-general', false ) ) || is_array( get_option( 'rank-math-options-titles', false ) ),
				],
			];
		}


    private static function legacy_preview( string $source, int $page ) {
			$sources = self::legacy_import_sources();
			if ( empty( $sources[$source]['available'] ) ) return new WP_Error( 'source', '找不到可匯入的舊 SEO 設定。' );
			$source_options = [];
			$meta_map = [];
			if ( 'yoast' === $source ) {
				$source_options = (array) get_option( 'wpseo_titles', [] );
				$global_map = [
					'google_verify'      => [ 'googleverify' ],
					'bing_verify'        => [ 'bingverify' ],
					'organization_name'  => [ 'company_name', 'website_name' ],
					'organization_alt_name' => [ 'alternate_website_name' ],
				];
				$meta_map = [
					'_wu_seo_title'       => '_yoast_wpseo_title',
					'_wu_seo_description' => '_yoast_wpseo_metadesc',
					'_wu_seo_canonical'   => '_yoast_wpseo_canonical',
					'_wu_seo_og_title'    => '_yoast_wpseo_opengraph-title',
					'_wu_seo_og_desc'     => '_yoast_wpseo_opengraph-description',
					'_wu_seo_og_image'    => '_yoast_wpseo_opengraph-image-id',
				];
			} else {
				$source_options = array_merge( (array) get_option( 'rank-math-options-general', [] ), (array) get_option( 'rank-math-options-titles', [] ) );
				$global_map = [
					'google_verify'       => [ 'google_verify' ],
					'bing_verify'         => [ 'bing_verify' ],
					'organization_name'   => [ 'knowledgegraph_name', 'website_name' ],
					'organization_alt_name' => [ 'knowledgegraph_alt_name' ],
					'default_og_image'    => [ 'knowledgegraph_logo' ],
				];
				$meta_map = [
					'_wu_seo_title'       => 'rank_math_title',
					'_wu_seo_description' => 'rank_math_description',
					'_wu_seo_canonical'   => 'rank_math_canonical_url',
					'_wu_seo_og_title'    => 'rank_math_facebook_title',
					'_wu_seo_og_desc'     => 'rank_math_facebook_description',
					'_wu_seo_og_image'    => 'rank_math_facebook_image_id',
				];
			}

			$batch = [ 'normal'=>[], 'canonical'=>[], 'noindex'=>[] ];
			$settings = (array) get_option( self::OPTION_KEY, [] );
			if ( 1 === $page ) foreach ( $global_map as $target=>$keys ) {
				foreach ( $keys as $key ) {
					if ( empty( $source_options[$key] ) || ! is_scalar( $source_options[$key] ) ) continue;
					$value = 'default_og_image' === $target ? absint( $source_options[$key] ) : sanitize_text_field( $source_options[$key] );
					if ( $value && (string) ( $settings[$target] ?? '' ) !== (string) $value ) {
						$batch['normal'][] = [ 'id'=>0,'key'=>$target,'old'=>$settings[$target] ?? '', 'new'=>$value ];
					}
					break;
				}
			}
			$query = new WP_Query( [ 'post_type'=>self::supported_post_types(), 'post_status'=>'any', 'posts_per_page'=>50,
				'paged'=>$page,'orderby'=>'ID','order'=>'ASC','fields'=>'ids' ] );
			foreach ( $query->posts as $id ) {
				if ( ! current_user_can( 'edit_post',$id ) ) continue;
				$incoming = [];
				foreach ( $meta_map as $target=>$legacy ) {
					$value = get_post_meta( $id,$legacy,true );
					if ( ! is_scalar( $value ) || '' === $value ) continue;
					// The source plugins' template variables cannot be used as literal SEO text.
					if ( preg_match( '/%{1,2}[a-z_][a-z_0-9-]*%{1,2}/i', (string) $value ) ) continue;
					if ( $target === '_wu_seo_canonical' ) $value = esc_url_raw( $value, ['http','https'] );
					elseif ( $target === '_wu_seo_og_image' ) $value = absint( $value );
					else $value = sanitize_text_field( $value );
					if ( $value ) $incoming[$target] = $value;
				}
				$robots = (array) get_post_meta( $id,'yoast' === $source ? '_yoast_wpseo_meta-robots-noindex' : 'rank_math_robots',true );
				if ( in_array( 'noindex',$robots,true ) || in_array( '1',$robots,true ) ) $incoming['_wu_seo_noindex'] = 1;
				elseif ( in_array( 'index',$robots,true ) || ( 'yoast' === $source && in_array( '2',$robots,true ) ) ) $incoming['_wu_seo_noindex'] = 0;
				if ( in_array( 'nofollow',$robots,true ) || ( 'yoast' === $source && '1' === get_post_meta( $id,'_yoast_wpseo_meta-robots-nofollow',true ) ) ) $incoming['_wu_seo_nofollow'] = 1;
				foreach ( $incoming as $key=>$value ) {
					$current = get_post_meta( $id,$key,true );
					if ( ! is_scalar( $current ) || (string) $current === (string) $value ) continue;
					$group = $key === '_wu_seo_canonical' ? 'canonical' : ( $key === '_wu_seo_noindex' ? 'noindex' : 'normal' );
					$batch[$group][] = [ 'id'=>$id,'key'=>$key,'old'=>$current,'new'=>$value ];
				}
			}
			return [ 'source'=>$source,'label'=>$sources[$source]['label'],'page'=>$page,'next'=>$page < $query->max_num_pages,'rows'=>$batch ];
		}

		private static function legacy_apply( array $preview, array $choices ): array {
			$stats = ['label'=>$preview['label'],'settings'=>0,'posts'=>0,'fields'=>0,'source'=>$preview['source'],'next'=>$preview['next'],'page'=>$preview['page']];
			$posts = [];
			$settings = (array) get_option( self::OPTION_KEY, [] );
			foreach ( $preview['rows'] as $group=>$records ) {
				if ( $group !== 'normal' && empty( $choices[$group] ) ) continue;
				foreach ( $records as $row ) {
					$id = (int) $row['id'];
					if ( $id && !current_user_can( 'edit_post',$id ) ) continue;
					$current = $id ? get_post_meta( $id,$row['key'],true ) : ( $settings[$row['key']] ?? '' );
					$blank = $current === '' || $current === null || ( $row['key'] === 'default_og_image' && !$current );
					if ( (string) $current !== (string) $row['old'] || ( !$blank && empty( $choices['overwrite_'.$group] ) ) ) continue;
					if ( $id ) {
						if ( update_post_meta( $id,$row['key'],wp_slash( is_string( $row['new'] ) ? $row['new'] : (string) $row['new'] ) ) ) {
							$posts[$id] = true;
							$stats['fields']++;
						}
					} else {
						$settings[$row['key']] = $row['new']; $stats['settings']++;
					}
				}
			}
			if ( $stats['settings'] ) { update_option( self::OPTION_KEY,$settings ); self::$settings = null; }
			$stats['posts'] = count( $posts );
			return $stats;
		}

		private static function legacy_import_request() {
			$source = $_POST['wumetax_seo_import_source'] ?? '';
			if ( ! is_string( $source ) || !$source ) return null;
			if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'access', '沒有匯入權限。' );
			check_admin_referer( 'wumetax_seo_import','wumetax_seo_import_nonce' );
			$page = is_string( $_POST['import_page'] ?? null ) ? max( 1,absint( $_POST['import_page'] ) ) : 1;
			$key = 'wu_seo_legacy_' . get_current_user_id();
			if ( isset( $_POST['import_apply'] ) ) {
				$token = is_string( $_POST['import_token'] ?? null ) ? $_POST['import_token'] : '';
				$preview = get_transient( $key );
				if ( !$preview || !hash_equals( $preview['token'],$token ) || $preview['source'] !== $source ) return new WP_Error( 'preview', '預覽已失效，請重新產生。' );
				delete_transient( $key );
				return self::legacy_apply( $preview, $_POST );
			}
			$preview = self::legacy_preview( sanitize_key( $source ),$page );
			if ( is_wp_error( $preview ) ) return $preview;
			$preview['token'] = wp_generate_password( 32,false,false );
			set_transient( $key,$preview,15 * MINUTE_IN_SECONDS );
			return $preview;
		}

		private static function render_legacy_preview( array $preview ): void {
			echo '<section class="wu-seo-card"><h2>匯入差異預覽 · 第 ' . (int) $preview['page'] . ' 批</h2><form method="post">';
			wp_nonce_field( 'wumetax_seo_import','wumetax_seo_import_nonce' );
			echo '<input type="hidden" name="wumetax_seo_import_source" value="' . esc_attr( $preview['source'] ) . '">';
			echo '<input type="hidden" name="import_token" value="' . esc_attr( $preview['token'] ) . '">';
			foreach ( ['normal'=>'一般欄位','canonical'=>'Canonical','noindex'=>'noindex'] as $group=>$label ) {
				echo '<h3>' . esc_html( $label ) . '（' . count( $preview['rows'][$group] ) . '）</h3>';
				if ( !$preview['rows'][$group] ) continue;
				echo '<details' . ($group === 'normal' ? '' : ' open') . '><summary>查看欄位差異</summary><table class="widefat striped"><thead><tr><th>內容</th><th>欄位</th><th>目前值</th><th>來源值</th></tr></thead><tbody>';
				foreach ( $preview['rows'][$group] as $row ) {
					echo '<tr><td>' . esc_html( $row['id'] ? get_the_title( $row['id'] ) : '全站' ) . '</td><td>' . esc_html( $row['key'] ) . '</td><td>' . esc_html( $row['old'] === '' ? '—' : (string) $row['old'] ) . '</td><td>' . esc_html( (string) $row['new'] ) . '</td></tr>';
				}
				echo '</tbody></table></details>';
				if ( $group !== 'normal' ) echo '<p><label><input type="checkbox" name="' . esc_attr( $group ) . '"> 確認匯入 ' . esc_html( $label ) . '</label></p>';
				echo '<p><label><input type="checkbox" name="overwrite_' . esc_attr( $group ) . '"> 明確允許覆寫現有 ' . esc_html( $label ) . '</label></p>';
			}
			echo '<button class="button button-primary" name="import_apply" value="1">套用這批並保留未選覆寫的值</button></form>';
			if ( $preview['next'] ) {
				echo '<form method="post">'; wp_nonce_field( 'wumetax_seo_import','wumetax_seo_import_nonce' );
				echo '<input type="hidden" name="wumetax_seo_import_source" value="' . esc_attr( $preview['source'] ) . '"><button class="button" name="import_page" value="' . ( (int) $preview['page'] + 1 ) . '">預覽下一批</button></form>';
			}
			echo '</section>';
		}
}
