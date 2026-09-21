<?php
/**
 * ============================================================
 * WUMETAX BRAND PAGE TRANSITION
 * Version 2.2.0
 * ============================================================
 *
 * 功能：
 * - 品牌式全螢幕換頁動畫
 * - CSS + Vanilla JavaScript
 * - 不使用 jQuery
 * - 不使用 GSAP
 * - 不使用 GIF
 * - 不對 body / html 做 transform
 * - 避免 Blocksy Sticky Header / WP Admin Bar 位移
 * - 僅站內正常換頁觸發
 * - 同頁錨點、mailto、tel、下載、新分頁自動略過
 * - WooCommerce AJAX 按鈕自動略過
 * - Back / Forward Cache 相容
 * - prefers-reduced-motion 相容
 *
 * 後台：
 * 設定 → Wumetax 轉場動畫
 *
 * 可設定：
 * 1. 動畫主色
 * 2. Logo
 * 3. 品牌文字
 *
 * 預設：
 * 主色：#171b19
 * Logo：未設定
 * 文字：網站名稱
 *
 * ============================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


if ( ! class_exists( 'Wumetax_Brand_Transition_v220' ) ) {

	class Wumetax_Brand_Transition_v220 {


		const OPTION_KEY =
			'wumetax_brand_transition_settings';


		const SETTINGS_GROUP =
			'wumetax_brand_transition_group';


		const PAGE_SLUG =
			'wu-page-transition';



		/* =====================================================
		 * INIT
		 * ===================================================== */

		public static function init() {

			/*
			 * Admin
			 */
			add_action(
				'admin_init',
				[
					__CLASS__,
					'register_settings',
				]
			);


			add_action(
				'admin_menu',
				[
					__CLASS__,
					'admin_menu',
				]
			);


			add_action(
				'admin_enqueue_scripts',
				[
					__CLASS__,
					'admin_assets',
				]
			);


			/*
			 * Frontend
			 */
			add_action(
				'wp_head',
				[
					__CLASS__,
					'frontend_head',
				],
				2
			);


			add_action(
				'wp_body_open',
				[
					__CLASS__,
					'frontend_markup',
				],
				1
			);


			add_action(
				'wp_footer',
				[
					__CLASS__,
					'frontend_script',
				],
				99999
			);

		}



		/* =====================================================
		 * DEFAULT SETTINGS
		 * ===================================================== */

		private static function defaults() {

			$site_name =
				get_bloginfo(
					'name'
				);


			if ( ! $site_name ) {

				$site_name =
					'Wumetax';

			}


			return [

				/*
				 * 動畫主色
				 */
				'color' =>
					'#171b19',


				/*
				 * WordPress attachment ID
				 */
				'logo_id' =>
					0,


				/*
				 * 品牌文字
				 */
				'brand_text' =>
					$site_name,

			];

		}



		/* =====================================================
		 * GET SETTINGS
		 * ===================================================== */

		private static function get_settings() {

			$saved =
				get_option(
					self::OPTION_KEY,
					[]
				);


			if ( ! is_array( $saved ) ) {

				$saved =
					[];

			}


			return wp_parse_args(
				$saved,
				self::defaults()
			);

		}



		/* =====================================================
		 * SANITIZE
		 * ===================================================== */

		public static function sanitize_settings(
			$input
		) {

			$defaults =
				self::defaults();


			$output =
				$defaults;


			if ( ! is_array( $input ) ) {

				return $output;

			}



			/* ---------------------------
			 * COLOR
			 * --------------------------- */

			if (
				isset(
					$input['color']
				)
			) {

				$color =
					sanitize_hex_color(
						$input['color']
					);


				if ( $color ) {

					$output['color'] =
						$color;

				}

			}



			/* ---------------------------
			 * LOGO
			 * --------------------------- */

			if (
				isset(
					$input['logo_id']
				)
			) {

				$output['logo_id'] =
					absint(
						$input['logo_id']
					);

			}



			/* ---------------------------
			 * BRAND TEXT
			 * --------------------------- */

			if (
				isset(
					$input['brand_text']
				)
			) {

				$text =
					sanitize_text_field(
						$input['brand_text']
					);


				if (
					$text !== ''
				) {

					$output['brand_text'] =
						$text;

				}

			}


			return $output;

		}



		/* =====================================================
		 * REGISTER SETTINGS
		 * ===================================================== */

		public static function register_settings() {

			register_setting(
				self::SETTINGS_GROUP,
				self::OPTION_KEY,
				[
					'type' =>
						'array',

					'sanitize_callback' =>
						[
							__CLASS__,
							'sanitize_settings',
						],

					'default' =>
						self::defaults(),
				]
			);

		}



		/* =====================================================
		 * ADMIN MENU
		 * ===================================================== */

		public static function admin_menu() {

			add_submenu_page(
				'wu-toolbox-modular',
				'Wumetax 轉場動畫',
				'Wumetax 轉場動畫',
				'manage_options',
				self::PAGE_SLUG,
				[
					__CLASS__,
					'admin_page',
				]
			);

		}



		/* =====================================================
		 * ADMIN ASSETS
		 * ===================================================== */

		public static function admin_assets(
			$hook
		) {

			$page = isset( $_GET['page'] )
				? sanitize_key( wp_unslash( $_GET['page'] ) )
				: '';

			if ( self::PAGE_SLUG !== $page ) {

				return;

			}


			wp_enqueue_media();

		}



		/* =====================================================
		 * ADMIN PAGE
		 * ===================================================== */

		public static function admin_page() {

			if (
				! current_user_can(
					'manage_options'
				)
			) {

				return;

			}


			$settings =
				self::get_settings();


			$logo_url =
				'';


			if (
				! empty(
					$settings['logo_id']
				)
			) {

				$logo_url =
					wp_get_attachment_image_url(
						absint(
							$settings['logo_id']
						),
						'medium'
					);

			}


			?>

			<style>

			.wupt-admin{
				max-width:960px;

				margin:
					30px 20px 60px 0;
			}


			.wupt-admin *{
				box-sizing:border-box;
			}


			.wupt-admin-header{
				margin-bottom:24px;
			}


			.wupt-admin-header h1{
				margin:
					0 0 8px;

				font-size:28px;
			}


			.wupt-admin-header p{
				margin:0;

				color:#646970;

				font-size:14px;
				line-height:1.7;
			}


			.wupt-admin-card{
				overflow:hidden;

				border:
					1px solid
					#dcdcde;

				border-radius:12px;

				background:#fff;

				box-shadow:
					0 4px 18px
					rgba(0,0,0,.025);
			}


			.wupt-admin-row{
				display:grid;

				grid-template-columns:
					220px
					minmax(0,1fr);

				gap:30px;

				padding:
					27px 30px;

				border-bottom:
					1px solid
					#ededed;
			}


			.wupt-admin-row:last-child{
				border-bottom:0;
			}


			.wupt-admin-label strong{
				display:block;

				margin-bottom:7px;

				color:#1d2327;

				font-size:14px;
			}


			.wupt-admin-label span{
				display:block;

				color:#757b80;

				font-size:12px;
				line-height:1.6;
			}



			/* =================================================
			   COLOR
			   ================================================= */

			.wupt-color-control{
				display:flex;

				align-items:center;

				gap:12px;
			}


			.wupt-color-control input[type="color"]{
				width:54px;
				height:42px;

				padding:3px;

				border:
					1px solid
					#c3c4c7;

				border-radius:7px;

				background:#fff;

				cursor:pointer;
			}


			.wupt-color-value{
				min-width:100px;

				padding:
					10px 12px;

				border:
					1px solid
					#dcdcde;

				border-radius:6px;

				background:#f8f9f8;

				color:#50575e;

				font-family:
					monospace;

				font-size:12px;
			}



			/* =================================================
			   LOGO
			   ================================================= */

			.wupt-logo-preview{
				display:flex;

				align-items:center;
				justify-content:center;

				width:180px;
				height:130px;

				margin-bottom:14px;

				overflow:hidden;

				border:
					1px dashed
					#c7ccca;

				border-radius:10px;

				background:
					#f8faf9;
			}


			.wupt-logo-preview img{
				display:block;

				max-width:140px;
				max-height:95px;

				width:auto;
				height:auto;

				object-fit:contain;
			}


			.wupt-logo-placeholder{
				color:#8c9690;

				font-size:13px;
			}


			.wupt-logo-buttons{
				display:flex;

				flex-wrap:wrap;

				gap:8px;
			}



			/* =================================================
			   TEXT
			   ================================================= */

			.wupt-admin-text{
				width:100%;
				max-width:460px;

				min-height:42px;
			}



			/* =================================================
			   PREVIEW
			   ================================================= */

			.wupt-preview-wrap{
				margin-top:24px;
			}


			.wupt-preview-title{
				margin:
					0 0 12px;

				font-size:15px;
				font-weight:650;
			}


			.wupt-preview{
				position:relative;

				display:flex;

				align-items:center;
				justify-content:center;

				min-height:350px;

				overflow:hidden;

				border:
					1px solid
					#dcdcde;

				border-radius:12px;

				background:
					radial-gradient(
						circle at 50% 42%,
						#ffffff 0%,
						#f9fbfa 42%,
						#f6faf7 100%
					);
			}


			.wupt-preview-inner{
				display:flex;

				flex-direction:column;

				align-items:center;

				text-align:center;
			}


			.wupt-preview-ring{
				position:relative;

				display:flex;

				align-items:center;
				justify-content:center;

				width:120px;
				height:120px;

				margin-bottom:23px;

				border:
					2px solid
					#e9ecea;

				border-radius:50%;
			}


			.wupt-preview-ring::before{
				content:"";

				position:absolute;

				inset:-2px;

				border:
					3px solid
					transparent;

				border-top-color:
					var(
						--admin-wupt-color,
						#171b19
					);

				border-right-color:
					var(
						--admin-wupt-color,
						#171b19
					);

				border-radius:50%;
			}


			.wupt-preview-logo{
				display:flex;

				align-items:center;
				justify-content:center;

				width:78px;
				height:78px;

				overflow:hidden;

				border-radius:50%;

				background:#fff;

				box-shadow:
					0 8px 26px
					rgba(0,0,0,.06);
			}


			.wupt-preview-logo img{
				max-width:62px;
				max-height:62px;

				object-fit:contain;
			}


			.wupt-preview-fallback{
				font-size:24px;
				font-weight:800;

				color:
					var(
						--admin-wupt-color,
						#171b19
					);
			}


			.wupt-preview-brand{
				margin:
					0 0 7px;

				color:#171b19;

				font-size:22px;
				font-weight:750;
			}


			.wupt-preview-subtitle{
				margin:0;

				color:#758079;

				font-size:13px;
			}



			/* =================================================
			   SAVE
			   ================================================= */

			.wupt-admin-save{
				margin-top:22px;
			}


			.wupt-admin-save .button{
				min-height:42px;

				padding:
					0 22px;
			}



			/* =================================================
			   RESPONSIVE
			   ================================================= */

			@media(max-width:760px){

				.wupt-admin-row{
					grid-template-columns:1fr;

					gap:15px;
				}

			}

			</style>



			<div class="wrap wupt-admin">


				<div class="wupt-admin-header">

					<h1>
						Wumetax 轉場動畫
					</h1>

					<p>
						設定網站換頁時顯示的品牌顏色、Logo 與文字。
						設定完成後會自動套用到全站站內換頁。
					</p>

				</div>



				<form
					method="post"
					action="options.php"
				>


					<?php
					settings_fields(
						self::SETTINGS_GROUP
					);
					?>


					<div class="wupt-admin-card">


						<!-- ======================================
						     COLOR
						======================================= -->

						<div class="wupt-admin-row">


							<div class="wupt-admin-label">

								<strong>
									動畫主色
								</strong>

								<span>
									控制 Logo 外圍圓弧與載入圓點顏色。
									預設為黑色。
								</span>

							</div>



							<div>

								<div class="wupt-color-control">


									<input
										type="color"
										id="wupt-admin-color"
										name="<?php echo esc_attr( self::OPTION_KEY ); ?>[color]"
										value="<?php echo esc_attr( $settings['color'] ); ?>"
									>


									<span
										class="wupt-color-value"
										id="wupt-admin-color-value"
									>
										<?php
										echo esc_html(
											$settings['color']
										);
										?>
									</span>


								</div>

							</div>


						</div>



						<!-- ======================================
						     LOGO
						======================================= -->

						<div class="wupt-admin-row">


							<div class="wupt-admin-label">

								<strong>
									Logo 圖片
								</strong>

								<span>
									選擇轉場動畫中央顯示的圖片。
								</span>

							</div>



							<div>


								<input
									type="hidden"
									id="wupt-logo-id"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[logo_id]"
									value="<?php echo esc_attr( absint( $settings['logo_id'] ) ); ?>"
								>



								<div
									class="wupt-logo-preview"
									id="wupt-logo-preview"
								>


									<?php if ( $logo_url ) : ?>


										<img
											src="<?php echo esc_url( $logo_url ); ?>"
											alt=""
										>


									<?php else : ?>


										<span class="wupt-logo-placeholder">
											請上傳圖片
										</span>


									<?php endif; ?>


								</div>



								<div class="wupt-logo-buttons">


									<button
										type="button"
										class="button button-secondary"
										id="wupt-upload-logo"
									>
										選擇圖片
									</button>


									<button
										type="button"
										class="button"
										id="wupt-remove-logo"
										<?php
										if (
											empty(
												$settings['logo_id']
											)
										) {
											echo 'style="display:none;"';
										}
										?>
									>
										移除圖片
									</button>


								</div>


							</div>


						</div>



						<!-- ======================================
						     TEXT
						======================================= -->

						<div class="wupt-admin-row">


							<div class="wupt-admin-label">

								<strong>
									顯示文字
								</strong>

								<span>
									Logo 下方顯示的品牌名稱。
									預設會自動使用 WordPress 網站名稱。
								</span>

							</div>



							<div>


								<input
									type="text"
									id="wupt-brand-text"
									class="regular-text wupt-admin-text"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[brand_text]"
									value="<?php echo esc_attr( $settings['brand_text'] ); ?>"
									placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
								>


							</div>


						</div>


					</div>



					<!-- ==========================================
					     LIVE PREVIEW
					=========================================== -->

					<div class="wupt-preview-wrap">


						<div class="wupt-preview-title">
							預覽
						</div>


						<div
							class="wupt-preview"
							id="wupt-admin-preview"
							style="--admin-wupt-color:<?php echo esc_attr( $settings['color'] ); ?>;"
						>


							<div class="wupt-preview-inner">


								<div class="wupt-preview-ring">


									<div
										class="wupt-preview-logo"
										id="wupt-preview-logo"
									>


										<?php if ( $logo_url ) : ?>


											<img
												src="<?php echo esc_url( $logo_url ); ?>"
												alt=""
											>


										<?php else : ?>


											<span class="wupt-preview-fallback">

												<?php
												echo esc_html(
													self::first_character(
														$settings['brand_text']
													)
												);
												?>

											</span>


										<?php endif; ?>


									</div>


								</div>



								<p
									class="wupt-preview-brand"
									id="wupt-preview-brand"
								>
									<?php
									echo esc_html(
										$settings['brand_text']
									);
									?>
								</p>


								<p class="wupt-preview-subtitle">
									正在準備網站內容 •••
								</p>


							</div>


						</div>


					</div>



					<div class="wupt-admin-save">


						<?php
						submit_button(
							'儲存轉場設定',
							'primary',
							'submit',
							false
						);
						?>


					</div>


				</form>


			</div>



			<script>

			(function(){

				'use strict';


				/* =================================================
				   ELEMENTS
				   ================================================= */

				const colorInput =
					document.getElementById(
						'wupt-admin-color'
					);


				const colorValue =
					document.getElementById(
						'wupt-admin-color-value'
					);


				const logoId =
					document.getElementById(
						'wupt-logo-id'
					);


				const logoPreview =
					document.getElementById(
						'wupt-logo-preview'
					);


				const previewLogo =
					document.getElementById(
						'wupt-preview-logo'
					);


				const uploadButton =
					document.getElementById(
						'wupt-upload-logo'
					);


				const removeButton =
					document.getElementById(
						'wupt-remove-logo'
					);


				const brandInput =
					document.getElementById(
						'wupt-brand-text'
					);


				const previewBrand =
					document.getElementById(
						'wupt-preview-brand'
					);


				const preview =
					document.getElementById(
						'wupt-admin-preview'
					);


				let mediaFrame =
					null;



				/* =================================================
				   FIRST CHARACTER
				   ================================================= */

				function firstCharacter(
					value
				){

					const text =
						String(
							value
							||
							'W'
						)
						.trim();


					return (
						Array.from(
							text
						)[0]
						||
						'W'
					);

				}



				/* =================================================
				   COLOR
				   ================================================= */

				colorInput.addEventListener(
					'input',
					function(){

						const color =
							colorInput.value;


						colorValue.textContent =
							color;


						preview.style.setProperty(
							'--admin-wupt-color',
							color
						);

					}
				);



				/* =================================================
				   TEXT
				   ================================================= */

				brandInput.addEventListener(
					'input',
					function(){

						const value =
							brandInput.value.trim()
							||
							<?php
							echo wp_json_encode(
								get_bloginfo(
									'name'
								)
							);
							?>;


						previewBrand.textContent =
							value;


						/*
						 * 沒 Logo 時更新 fallback
						 */
						if(
							!logoId.value
						){

							previewLogo.innerHTML =
								'';


							const fallback =
								document.createElement(
									'span'
								);


							fallback.className =
								'wupt-preview-fallback';


							fallback.textContent =
								firstCharacter(
									value
								);


							previewLogo.appendChild(
								fallback
							);

						}

					}
				);



				/* =================================================
				   MEDIA UPLOADER
				   ================================================= */

				uploadButton.addEventListener(
					'click',
					function(event){

						event.preventDefault();


						if(
							mediaFrame
						){

							mediaFrame.open();

							return;

						}


						mediaFrame =
							wp.media(
								{

									title:
										'選擇轉場 Logo',

									button:
										{
											text:
												'使用這張圖片'
										},

									library:
										{
											type:
												'image'
										},

									multiple:
										false

								}
							);



						mediaFrame.on(
							'select',
							function(){

								const attachment =
									mediaFrame
										.state()
										.get(
											'selection'
										)
										.first()
										.toJSON();


								if(
									!attachment
									||
									!attachment.id
								){

									return;

								}


								const imageUrl =
									attachment.sizes
									&&
									attachment.sizes.medium
									? attachment.sizes.medium.url
									: attachment.url;



								/*
								 * ID
								 */
								logoId.value =
									attachment.id;



								/*
								 * 後台設定 preview
								 */
								logoPreview.innerHTML =
									'';


								const image =
									document.createElement(
										'img'
									);


								image.src =
									imageUrl;


								image.alt =
									'';


								logoPreview.appendChild(
									image
								);



								/*
								 * Full preview
								 */
								previewLogo.innerHTML =
									'';


								const previewImage =
									document.createElement(
										'img'
									);


								previewImage.src =
									imageUrl;


								previewImage.alt =
									'';


								previewLogo.appendChild(
									previewImage
								);


								removeButton.style.display =
									'';

							}
						);


						mediaFrame.open();

					}
				);



				/* =================================================
				   REMOVE LOGO
				   ================================================= */

				removeButton.addEventListener(
					'click',
					function(event){

						event.preventDefault();


						logoId.value =
							'';


						logoPreview.innerHTML =
							'<span class="wupt-logo-placeholder">請上傳圖片</span>';


						previewLogo.innerHTML =
							'';


						const fallback =
							document.createElement(
								'span'
							);


						fallback.className =
							'wupt-preview-fallback';


						fallback.textContent =
							firstCharacter(
								brandInput.value
							);


						previewLogo.appendChild(
							fallback
						);


						removeButton.style.display =
							'none';

					}
				);


			})();

			</script>


			<?php

		}



		/* =====================================================
		 * FIRST CHARACTER
		 * ===================================================== */

		private static function first_character(
			$text
		) {

			$text =
				trim(
					wp_strip_all_tags(
						(string) $text
					)
				);


			if ( $text === '' ) {

				return 'W';

			}


			if (
				function_exists(
					'mb_substr'
				)
			) {

				return mb_substr(
					$text,
					0,
					1,
					'UTF-8'
				);

			}


			return substr(
				$text,
				0,
				1
			);

		}



		/* =====================================================
		 * LOGO URL
		 * ===================================================== */

		private static function get_logo_url() {

			$settings =
				self::get_settings();


			$logo_id =
				absint(
					$settings['logo_id']
				);


			if ( ! $logo_id ) {

				return '';

			}


			$url =
				wp_get_attachment_image_url(
					$logo_id,
					'full'
				);


			return $url
				? $url
				: '';

		}



		/* =====================================================
		 * FRONTEND HEAD
		 * ===================================================== */

		public static function frontend_head() {

			if ( is_admin() ) {
				return;
			}


			$settings =
				self::get_settings();


			$accent =
				$settings['color'];


			?>

			<style id="wumetax-transition-v220-css">

			:root{

				--wupt-bg:
					#f6faf7;

				--wupt-dark:
					#171b19;

				--wupt-accent:
					<?php echo esc_html( $accent ); ?>;

				--wupt-muted:
					#758079;

			}



			/* =================================================
			   OVERLAY
			   ================================================= */

			#wumetax-transition{
				position:fixed;

				z-index:2147483000;

				inset:0;

				display:flex;
				align-items:center;
				justify-content:center;

				margin:0 !important;
				padding:0 !important;

				background:
					radial-gradient(
						circle at 50% 42%,
						#ffffff 0%,
						#f9fbfa 42%,
						var(--wupt-bg) 100%
					);

				opacity:0;
				visibility:hidden;

				pointer-events:none;

				transition:
					opacity .28s
					cubic-bezier(
						.22,
						.61,
						.36,
						1
					),
					visibility .28s linear;

				contain:
					layout paint;
			}



			#wumetax-transition::before{
				content:"";

				position:absolute;

				left:50%;
				top:50%;

				width:
					min(
						430px,
						76vw
					);

				aspect-ratio:
					1;

				border-radius:
					50%;

				background:
					radial-gradient(
						circle,
						rgba(
							23,
							27,
							25,
							.035
						)
						0%,
						transparent
						70%
					);

				transform:
					translate(
						-50%,
						-50%
					);

				pointer-events:none;
			}



			html.wupt-show
			#wumetax-transition{
				opacity:1;
				visibility:visible;

				pointer-events:auto;
			}



			/* =================================================
			   INNER
			   ================================================= */

			.wupt-inner{
				position:relative;

				z-index:2;

				display:flex;

				flex-direction:column;

				align-items:center;
				justify-content:center;

				width:
					min(
						420px,
						85vw
					);

				text-align:center;

				opacity:.9;

				transform:
					translateY(7px)
					scale(.992);

				transition:
					opacity .32s ease,
					transform .38s
					cubic-bezier(
						.22,
						.61,
						.36,
						1
					);
			}



			html.wupt-show
			.wupt-inner{
				opacity:1;

				transform:
					translateY(0)
					scale(1);
			}



			/* =================================================
			   LOGO / RINGS
			   ================================================= */

			.wupt-mark{
				position:relative;

				display:flex;

				align-items:center;
				justify-content:center;

				width:126px;
				height:126px;

				margin-bottom:28px;
			}



			.wupt-ring-bg,
			.wupt-ring-one,
			.wupt-ring-two{
				position:absolute;

				border-radius:50%;

				pointer-events:none;
			}



			.wupt-ring-bg{
				inset:0;

				border:
					1px solid
					#e7e9e8;
			}



			.wupt-ring-one{
				inset:0;

				border:
					3px solid
					transparent;

				border-top-color:
					var(--wupt-accent);

				border-right-color:
					var(--wupt-accent);

				border-radius:
					50%;

				transform:
					rotate(28deg);

				animation:
					wuptRotateOne
					1.15s
					linear
					infinite;

				will-change:
					transform;
			}



			.wupt-ring-two{
				inset:10px;

				border:
					2px solid
					transparent;

				border-bottom-color:
					var(--wupt-accent);

				border-radius:
					50%;

				opacity:.32;

				animation:
					wuptRotateTwo
					1.8s
					linear
					infinite;

				will-change:
					transform;
			}



			@keyframes wuptRotateOne{

				to{

					transform:
						rotate(388deg);

				}

			}



			@keyframes wuptRotateTwo{

				to{

					transform:
						rotate(-360deg);

				}

			}



			/* =================================================
			   LOGO
			   ================================================= */

			.wupt-logo-box{
				position:relative;

				z-index:3;

				display:flex;

				align-items:center;
				justify-content:center;

				width:82px;
				height:82px;

				overflow:hidden;

				border-radius:50%;

				background:#fff;

				box-shadow:
					0 10px 32px
					rgba(
						31,
						63,
						40,
						.065
					);
			}



			.wupt-logo{
				display:block;

				width:66px;
				height:66px;

				object-fit:contain;
			}



			.wupt-logo-fallback{
				display:flex;

				align-items:center;
				justify-content:center;

				width:66px;
				height:66px;

				border-radius:50%;

				background:
					#f3f4f3;

				color:
					var(--wupt-accent);

				font-size:27px;
				font-weight:800;
			}



			/* =================================================
			   TEXT
			   ================================================= */

			.wupt-brand{
				margin:
					0 0 8px
					!important;

				color:
					var(--wupt-dark)
					!important;

				font-size:
					23px
					!important;

				font-weight:
					750
					!important;

				line-height:
					1.3
					!important;

				letter-spacing:
					.01em;
			}



			.wupt-subtitle{
				display:flex;

				align-items:center;
				justify-content:center;

				gap:4px;

				margin:
					0
					!important;

				color:
					var(--wupt-muted)
					!important;

				font-size:
					13px
					!important;

				font-weight:
					500
					!important;

				line-height:
					1.7
					!important;
			}



			/* =================================================
			   DOTS
			   ================================================= */

			.wupt-dots{
				display:inline-flex;

				gap:4px;

				margin-left:3px;
			}



			.wupt-dot{
				width:4px;
				height:4px;

				border-radius:50%;

				background:
					var(--wupt-accent);

				animation:
					wuptDot
					1.05s
					infinite
					ease-in-out;
			}



			.wupt-dot:nth-child(2){
				animation-delay:.14s;
			}


			.wupt-dot:nth-child(3){
				animation-delay:.28s;
			}



			@keyframes wuptDot{

				0%,
				70%,
				100%{

					opacity:.25;

					transform:
						translateY(0);

				}


				35%{

					opacity:1;

					transform:
						translateY(-3px);

				}

			}



			/* =================================================
			   MOBILE
			   ================================================= */

			@media(max-width:700px){

				.wupt-mark{
					width:112px;
					height:112px;

					margin-bottom:25px;
				}


				.wupt-logo-box{
					width:73px;
					height:73px;
				}


				.wupt-logo,
				.wupt-logo-fallback{
					width:58px;
					height:58px;
				}


				.wupt-logo-fallback{
					font-size:23px;
				}


				.wupt-brand{
					font-size:
						21px
						!important;
				}


				.wupt-subtitle{
					font-size:
						12px
						!important;
				}

			}



			/* =================================================
			   ACCESSIBILITY
			   ================================================= */

			@media(
				prefers-reduced-motion:
				reduce
			){

				#wumetax-transition,
				.wupt-inner{
					transition:
						none
						!important;
				}


				.wupt-ring-one,
				.wupt-ring-two,
				.wupt-dot{
					animation:
						none
						!important;

					will-change:
						auto
						!important;
				}

			}

			</style>



			<script id="wumetax-transition-v220-early">

			(function(){

				'use strict';


				try{

					const raw =
						sessionStorage.getItem(
							'wumetax_page_transition_v220'
						);


					const startedAt =
						raw
							? parseInt(
								raw,
								10
							)
							: 0;


					const fresh =
						startedAt > 0
						&&
						(
							Date.now()
							-
							startedAt
						)
						<
						8000;


					if(
						fresh
					){

						document
							.documentElement
							.classList
							.add(
								'wupt-show'
							);

					}else if(
						raw
					){

						sessionStorage.removeItem(
							'wumetax_page_transition_v220'
						);

					}

				}catch(error){}


			})();

			</script>


			<?php

		}



		/* =====================================================
		 * FRONTEND MARKUP
		 * ===================================================== */

		public static function frontend_markup() {

			if ( is_admin() ) {
				return;
			}


			$settings =
				self::get_settings();


			$logo_url =
				self::get_logo_url();


			$brand_text =
				$settings['brand_text'];


			?>

			<div
				id="wumetax-transition"
				aria-hidden="true"
			>


				<div class="wupt-inner">


					<div
						class="wupt-mark"
						aria-hidden="true"
					>


						<div class="wupt-ring-bg"></div>


						<div class="wupt-ring-one"></div>


						<div class="wupt-ring-two"></div>



						<div class="wupt-logo-box">


							<?php if ( $logo_url ) : ?>


								<img
									class="wupt-logo"
									src="<?php echo esc_url( $logo_url ); ?>"
									alt=""
									decoding="async"
								>


							<?php else : ?>


								<div class="wupt-logo-fallback">

									<?php
									echo esc_html(
										self::first_character(
											$brand_text
										)
									);
									?>

								</div>


							<?php endif; ?>


						</div>


					</div>



					<p class="wupt-brand">

						<?php
						echo esc_html(
							$brand_text
						);
						?>

					</p>



					<p class="wupt-subtitle">


						<span>
							正在準備網站內容
						</span>


						<span
							class="wupt-dots"
							aria-hidden="true"
						>


							<span class="wupt-dot"></span>

							<span class="wupt-dot"></span>

							<span class="wupt-dot"></span>


						</span>


					</p>


				</div>


			</div>


			<?php

		}



		/* =====================================================
		 * FRONTEND SCRIPT
		 * ===================================================== */

		public static function frontend_script() {

			if ( is_admin() ) {
				return;
			}

			?>

			<script id="wumetax-transition-v220-js">

			(function(){

				'use strict';


				const html =
					document.documentElement;


				let leaving =
					false;


				let arrivedFromTransition =
					false;



				/* =================================================
				   CHECK ARRIVAL
				   ================================================= */

				try{

					const raw =
						sessionStorage.getItem(
							'wumetax_page_transition_v220'
						);


					const startedAt =
						raw
							? parseInt(
								raw,
								10
							)
							: 0;


					arrivedFromTransition =
						startedAt > 0
						&&
						(
							Date.now()
							-
							startedAt
						)
						<
						8000;


					if(
						!arrivedFromTransition
						&&
						raw
					){

						sessionStorage.removeItem(
							'wumetax_page_transition_v220'
						);

					}

				}catch(error){}



				/* =================================================
				   FINISH ARRIVAL
				   ================================================= */

				function finishArrival(){

					const delay =
						arrivedFromTransition
							? 220
							: 0;


					window.setTimeout(
						function(){

							html.classList.remove(
								'wupt-show'
							);


							try{

								sessionStorage.removeItem(
									'wumetax_page_transition_v220'
								);

							}catch(error){}


							leaving =
								false;

						},
						delay
					);

				}



				/*
				 * 不等待整頁圖片載入。
				 * DOM Ready 後即可關閉 Loader。
				 */

				if(
					arrivedFromTransition
				){

					if(
						document.readyState ===
							'loading'
					){

						document.addEventListener(
							'DOMContentLoaded',
							finishArrival,
							{
								once:true
							}
						);

					}else{

						finishArrival();

					}

				}else{

					html.classList.remove(
						'wupt-show'
					);

				}



				/* =================================================
				   BACK / FORWARD CACHE
				   ================================================= */

				window.addEventListener(
					'pageshow',
					function(event){

						if(
							!event.persisted
						){

							return;

						}


						html.classList.remove(
							'wupt-show'
						);


						leaving =
							false;


						try{

							sessionStorage.removeItem(
								'wumetax_page_transition_v220'
							);

						}catch(error){}

					}
				);



				/* =================================================
				   CHECK LINK
				   ================================================= */

				function canTransition(
					link,
					event
				){

					if(
						!link
						||
						leaving
						||
						event.defaultPrevented
					){

						return false;

					}



					/*
					 * Modifier keys
					 */

					if(
						event.metaKey
						||
						event.ctrlKey
						||
						event.shiftKey
						||
						event.altKey
					){

						return false;

					}



					/*
					 * 非左鍵
					 */

					if(
						typeof event.button !==
							'undefined'
						&&
						event.button !== 0
					){

						return false;

					}



					/*
					 * WP Admin Bar
					 */

					if(
						link.closest(
							'#wpadminbar'
						)
					){

						return false;

					}



					/*
					 * Download / new window
					 */

					if(
						link.hasAttribute(
							'download'
						)
						||
						link.target ===
							'_blank'
					){

						return false;

					}



					/*
					 * 手動忽略
					 */

					if(
						link.hasAttribute(
							'data-no-transition'
						)
						||
						link.closest(
							'[data-no-transition]'
						)
					){

						return false;

					}



					/*
					 * WooCommerce AJAX
					 */

					if(
						link.matches(
							'.ajax_add_to_cart, .add_to_cart_button, .remove_from_cart_button'
						)
						||
						link.closest(
							'.ajax_add_to_cart, .add_to_cart_button, .remove_from_cart_button'
						)
					){

						return false;

					}



					const href =
						link.getAttribute(
							'href'
						);



					if(
						!href
						||
						href === '#'
					){

						return false;

					}



					if(
						href.startsWith(
							'mailto:'
						)
						||
						href.startsWith(
							'tel:'
						)
						||
						href.startsWith(
							'javascript:'
						)
					){

						return false;

					}



					let url;



					try{

						url =
							new URL(
								link.href,
								window.location.href
							);

					}catch(error){

						return false;

					}



					/*
					 * 外部網站
					 */

					if(
						url.origin !==
							window.location.origin
					){

						return false;

					}



					/*
					 * WordPress 後台
					 */

					if(
						url.pathname.startsWith(
							'/wp-admin/'
						)
						||
						url.pathname.includes(
							'wp-login.php'
						)
					){

						return false;

					}



					/*
					 * 完全相同 URL
					 */

					if(
						url.href ===
							window.location.href
					){

						return false;

					}



					/*
					 * 同頁 anchor
					 */

					if(
						url.pathname ===
							window.location.pathname
						&&
						url.search ===
							window.location.search
						&&
						url.hash
					){

						return false;

					}



					return true;

				}



				/* =================================================
				   CLICK
				   ================================================= */

				document.addEventListener(
					'click',
					function(event){

						if(
							!(
								event.target
								instanceof
								Element
							)
						){

							return;

						}


						const link =
							event.target.closest(
								'a'
							);



						if(
							!canTransition(
								link,
								event
							)
						){

							return;

						}



						event.preventDefault();


						leaving =
							true;



						try{

							sessionStorage.setItem(
								'wumetax_page_transition_v220',
								String(
									Date.now()
								)
							);

						}catch(error){}



						html.classList.add(
							'wupt-show'
						);



						const destination =
							link.href;



						/*
						 * 約 260ms：
						 * Loader 先完整淡入再換頁。
						 */

						window.setTimeout(
							function(){

								window.location.assign(
									destination
								);

							},
							260
						);

					}
				);


			})();

			</script>


			<?php

		}


	}


	Wumetax_Brand_Transition_v220::init();

}
