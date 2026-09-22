<?php

 /**  * Plugin Name: Wu AI Translate for TranslatePress  * Plugin URI:  https://github.com/Ya19880104/wu-translatepress-addons  * Description: AI 翻譯外掛，支援 Gemini / Anthropic Claude / Perplexity / OpenAI ChatGPT 四種供應商。設定頁一鍵「全站掃描」探索字串（含前台實際可見文字與 TranslatePress 實際字典精準同步；避免 Builder/CSS 技術字串，並修正重複 block_type 寫入），並提供翻譯進度統計、CSV 匯出／匯入（含 Excel 相容編碼修正），匯出頁附帶可一鍵複製的 AI 翻譯指令。  * Version:     1.9.6  * Author:      Ya19880104  * Text Domain: wu-ai-translate  * Requires PHP: 7.4  */  if ( ! defined( 'ABSPATH' ) ) { 	exit; }  define( 'WU_AIT_VERSION', '1.9.6' ); define( 'WU_AIT_FILE', __FILE__ ); define( 'WU_AIT_DIR', plugin_dir_path( __FILE__ ) ); define( 'WU_AIT_URL', plugin_dir_url( __FILE__ ) ); define( 'WU_AIT_OPTION_KEY', 'wu_ait_settings' ); define( 'WU_AIT_LOG_OPTION_KEY', 'wu_ait_log' ); define( 'WU_AIT_CRON_HOOK', 'wu_ait_cron_batch_translate' );  register_activation_hook( WU_AIT_FILE, function () { 	if ( ! wp_next_scheduled( WU_AIT_CRON_HOOK ) ) { 		wp_schedule_event( time() + 60, 'wu_ait_five_minutes', WU_AIT_CRON_HOOK ); 	} } );  register_deactivation_hook( WU_AIT_FILE, function () { 	wp_clear_scheduled_hook( WU_AIT_CRON_HOOK ); } );  add_filter( 'cron_schedules', function ( $schedules ) { 	$schedules['wu_ait_five_minutes'] = array( 		'interval' => 300, 		'display'  => __( '每 5 分鐘（Wu AI 翻譯批次）', 'wu-ai-translate' ), 	); 	return $schedules; } );  function wu_ait_sanitize_model_code( string $raw ): string { 	$model = trim( $raw ); 	$model = str_replace( array( "\r", "\n", "\t" ), '', $model ); 	$model = preg_replace( '/\x{3000}/u', '', $model ); 	$model = trim( $model, "\"'“”‘’ " ); 	$model = preg_replace( '/\s+/', '', $model ); 	if ( false !== strpos( $model, '/' ) ) { 		$parts = explode( '/', $model ); 		$model = end( $parts ); 	} 	return $model; }  /**  * 移除譯文中可能殘留的語言標記前綴，例如 "[EN] Hello"、"(EN) Hello"、"EN: Hello"。  *  * 修正說明（v1.9.1）：前一版的正規表達式把括號與冒號都設為可選字元，  * 導致任何以 2~5 個英文字母開頭、後面接空格的正常英文句子（例如 "This policy..."、  * "Applicable to..."）被誤判成帶語言標記而遭到裁切，造成翻譯內容被錯誤清空、  * 匯入時「比對成功」卻「實際寫入 0 筆」。  * 新規則要求「方括號」「圓括號」或「冒號」三者之一必須明確存在才會移除，  * 一般英文句子不會受影響。  */ function wu_ait_strip_language_prefix( string $text ): string { 	$pattern = '/^\s*(?:\[[A-Za-z]{2,5}\]|\([A-Za-z]{2,5}\)|[A-Za-z]{2,5}:)\s+/u'; 	return preg_replace( $pattern, '', $text, 1 ); }  function wu_ait_get_settings() { 	$defaults = array( 		'provider'           => 'gemini', 		'api_key_gemini'     => '', 		'api_key_claude'     => '', 		'api_key_perplexity' => '', 		'api_key_openai'     => '', 		'model_gemini'       => 'gemini-3.8-flash', 		'model_claude'       => 'claude-sonnet-4-5', 		'model_perplexity'   => 'sonar-pro', 		'model_openai'       => 'gpt-4o-mini', 		'temperature'        => 0.2, 		'batch_size'         => 10, 		'auto_cron'          => 0, 		'cache_enabled'      => 1, 		'custom_glossary'    => '', 		'extra_prompt'       => '', 		'fallback_provider'  => '', 	); 	$saved = get_option( WU_AIT_OPTION_KEY, array() ); 	$saved = wp_parse_args( $saved, $defaults );  	unset( $saved['auto_discover'] );  	foreach ( array( 'gemini', 'claude', 'perplexity', 'openai' ) as $key ) { 		$field = 'model_' . $key; 		if ( ! empty( $saved[ $field ] ) ) { 			$saved[ $field ] = wu_ait_sanitize_model_code( $saved[ $field ] ); 		} 	}  	return $saved; }  function wu_ait_update_settings( array $new_settings ) { 	$current = wu_ait_get_settings(); 	$merged  = wp_parse_args( $new_settings, $current ); 	update_option( WU_AIT_OPTION_KEY, $merged ); 	return $merged; }  function wu_ait_add_log( $message, $level = 'info' ) { 	$log   = get_option( WU_AIT_LOG_OPTION_KEY, array() ); 	$entry = array( 		'time'    => current_time( 'mysql' ), 		'level'   => $level, 		'message' => is_string( $message ) ? $message : wp_json_encode( $message ), 	); 	array_unshift( $log, $entry ); 	$log = array_slice( $log, 0, 200 ); 	update_option( WU_AIT_LOG_OPTION_KEY, $log, false ); }  
/**
 * 翻譯寫入後清除常見 WordPress / 頁面快取。
 * 不觸碰 Cloudflare 全站 Purge，避免過度清除 CDN；若站點本身有對應 hook，會一起觸發。
 */
function wu_ait_flush_site_caches(): array {
    $done = array();

    if ( function_exists( 'wp_cache_flush' ) ) {
        wp_cache_flush();
        $done[] = 'WordPress Object Cache';
    }

    if ( function_exists( 'rocket_clean_domain' ) ) {
        rocket_clean_domain();
        $done[] = 'WP Rocket';
    }

    if ( has_action( 'litespeed_purge_all' ) ) {
        do_action( 'litespeed_purge_all' );
        $done[] = 'LiteSpeed Cache';
    }

    if ( function_exists( 'wp_cache_clear_cache' ) ) {
        wp_cache_clear_cache();
        $done[] = 'WP Super Cache';
    }

    if ( class_exists( 'W3TC\\Dispatcher' ) ) {
        try {
            $svc = \W3TC\Dispatcher::component( 'CacheFlush' );
            if ( $svc && method_exists( $svc, 'flush_all' ) ) {
                $svc->flush_all();
                $done[] = 'W3 Total Cache';
            }
        } catch ( Throwable $e ) {
            // 快取清除失敗不影響翻譯寫入。
        }
    }

    do_action( 'wu_ait_after_translation_cache_flush' );
    return array_values( array_unique( $done ) );
}

/**  * ------------------------------------------------------------------  *  供應商介面與共用基底  * ------------------------------------------------------------------  */ interface WU_AIT_Provider_Interface { 	public function translate_batch( array $texts, string $target_lang, string $source_lang = 'zh-TW' ): array; 	public function verify_connection(): array; }  abstract class WU_AIT_Abstract_Provider implements WU_AIT_Provider_Interface {  	protected string $api_key; 	protected string $model; 	protected float  $temperature; 	protected string $glossary; 	protected string $extra_prompt; 	protected int    $max_retries      = 3; 	protected float  $base_backoff_sec = 2.0;  	public function __construct( string $api_key, string $model, float $temperature = 0.2, string $glossary = '', string $extra_prompt = '' ) { 		$this->api_key      = trim( $api_key ); 		$this->model        = wu_ait_sanitize_model_code( $model ); 		$this->temperature  = $temperature; 		$this->glossary     = $glossary; 		$this->extra_prompt = $extra_prompt; 	}  	protected function build_system_prompt( string $target_lang, string $source_lang ): string { 		$lines = array(); 		$lines[] = "你是一位專業的網站本地化翻譯員，請將使用者提供 JSON 陣列中的每一段文字，從語言代碼「{$source_lang}」翻譯成語言代碼「{$target_lang}」。"; 		$lines[] = "規則："; 		$lines[] = "1. 完整保留原文中的 HTML 標籤（例如 <strong>、<a href=\"...\">）、變數、佔位符（例如 %s、{name}、[shortcode]）與換行符號，不要新增或刪除標籤，只翻譯可視文字內容。"; 		$lines[] = "2. 保持原文的語氣、標點風格與大小寫慣例（如品牌名、專有名詞不要翻譯，除非常見翻譯）。"; 		$lines[] = "3. 絕對不要在譯文前後加上任何語言標記、前綴或後綴（例如不要輸出「[EN] Hello」，只輸出「Hello」）。"; 		$lines[] = "4. 不要增加解釋、註解或前後綴文字，只輸出翻譯結果本身。"; 		$lines[] = "5. 陣列中每個項目彼此獨立對應輸出，數量與順序必須與輸入完全一致，不可增減項目。"; 		$lines[] = "6. 輸出必須是合法 JSON，格式為 {\"translations\": [\"譯文1\", \"譯文2\", ...]}，不要加任何 Markdown code block 標記（如 ```json）或多餘文字。";  		if ( ! empty( $this->glossary ) ) { 			$lines[] = "7. 請嚴格套用以下自訂詞彙表（原文=譯文），遇到對應原文時一律使用指定譯文：\n" . $this->glossary; 		} 		if ( ! empty( $this->extra_prompt ) ) { 			$lines[] = "8. 額外風格指示：" . $this->extra_prompt; 		}  		return implode( "\n", $lines ); 	}  	protected function build_user_payload( array $texts ): string { 		return wp_json_encode( array( 'texts' => array_values( $texts ) ), JSON_UNESCAPED_UNICODE ); 	}  	protected function parse_translations( string $raw_text, int $expected_count ): array { 		$raw_text = trim( $raw_text ); 		$raw_text = preg_replace( '/^```(json)?/i', '', $raw_text ); 		$raw_text = preg_replace( '/```$/', '', $raw_text ); 		$raw_text = trim( $raw_text );  		$json = json_decode( $raw_text, true );  		if ( ! is_array( $json ) || empty( $json['translations'] ) ) { 			if ( preg_match( '/\{.*\}/s', $raw_text, $m ) ) { 				$json = json_decode( $m[0], true ); 			} 		}  		if ( ! is_array( $json ) || empty( $json['translations'] ) || ! is_array( $json['translations'] ) ) { 			return array( 'ok' => false, 'error' => 'AI 回應無法解析為預期 JSON 格式：' . substr( $raw_text, 0, 300 ) ); 		}  		$translations = $json['translations'];  		if ( count( $translations ) !== $expected_count ) { 			wu_ait_add_log( sprintf( '警告：預期 %d 筆翻譯，實際取得 %d 筆，將以可用部分回填。', $expected_count, count( $translations ) ), 'warning' ); 			if ( count( $translations ) < $expected_count ) { 				$translations = array_pad( $translations, $expected_count, '' ); 			} else { 				$translations = array_slice( $translations, 0, $expected_count ); 			} 		}  		$translations = array_map( function ( $t ) { 			return is_string( $t ) ? wu_ait_strip_language_prefix( $t ) : $t; 		}, $translations );  		return array( 'ok' => true, 'translations' => $translations ); 	}  	protected function http_post_json( string $url, array $headers, array $body, int $timeout = 45 ): array { 		$attempt    = 0; 		$last_error = '';  		while ( $attempt < $this->max_retries ) { 			$attempt++;  			$response = wp_remote_post( $url, array( 				'timeout' => $timeout, 				'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ), 				'body'    => wp_json_encode( $body ), 			) );  			if ( is_wp_error( $response ) ) { 				$last_error = $response->get_error_message(); 				wu_ait_add_log( "HTTP 請求錯誤（第 {$attempt} 次）：{$last_error}", 'error' ); 				$this->sleep_with_backoff( $attempt ); 				continue; 			}  			$code          = wp_remote_retrieve_response_code( $response ); 			$response_body = wp_remote_retrieve_body( $response );  			if ( $code >= 200 && $code < 300 ) { 				return array( 'ok' => true, 'body' => $response_body ); 			}  			if ( $code === 429 || $code >= 500 ) { 				$last_error = "HTTP {$code}：{$response_body}"; 				$hint = ( 503 === $code ) ? '（服務暫時過載，非金鑰或程式碼問題，將自動延長等待後重試）' : ''; 				wu_ait_add_log( "API 回應 {$code}{$hint}（第 {$attempt} 次重試）：{$response_body}", 'warning' ); 				if ( $attempt < $this->max_retries ) { 					$this->sleep_with_backoff( $attempt ); 				} 				continue; 			}  			$hint = ''; 			if ( 400 === $code && false !== stripos( $response_body, 'model name format' ) ) { 				$hint = sprintf( '（目前設定的模型代碼為「%s」，請確認格式正確）', $this->model ); 			} 			if ( ( 404 === $code || 400 === $code ) && false !== stripos( $response_body, 'not found' ) ) { 				$hint = sprintf( '（模型「%s」可能不存在或已下架，請按「驗證金鑰與模型」確認）', $this->model ); 			}  			return array( 'ok' => false, 'error' => "HTTP {$code}{$hint}：{$response_body}", 'retryable' => false ); 		}  		return array( 'ok' => false, 'error' => $last_error ?: '重試多次後仍失敗', 'retryable' => true ); 	}  	protected function sleep_with_backoff( int $attempt ): void { 		$delay  = $this->base_backoff_sec * ( 2 ** ( $attempt - 1 ) ); 		$jitter = wp_rand( 0, 1000 ) / 1000; 		$total  = min( $delay + $jitter, 30 ); 		usleep( (int) ( $total * 1000000 ) ); 	} }  class WU_AIT_Gemini_Provider extends WU_AIT_Abstract_Provider {  	public function translate_batch( array $texts, string $target_lang, string $source_lang = 'zh-TW' ): array { 		if ( empty( $this->api_key ) ) { 			return array( 'ok' => false, 'error' => 'Gemini API 金鑰未設定，請到「設定 → Wu AI 翻譯 → 供應商與 API 金鑰」填入金鑰並儲存。', 'retryable' => false ); 		} 		if ( empty( $this->model ) ) { 			return array( 'ok' => false, 'error' => 'Gemini 模型代碼未設定', 'retryable' => false ); 		}  		$system_prompt = $this->build_system_prompt( $target_lang, $source_lang ); 		$user_payload  = $this->build_user_payload( $texts );  		$url = sprintf( 			'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s', 			rawurlencode( $this->model ), 			rawurlencode( $this->api_key ) 		);  		$body = array( 			'contents'          => array( array( 'role' => 'user', 'parts' => array( array( 'text' => $user_payload ) ) ) ), 			'systemInstruction' => array( 'parts' => array( array( 'text' => $system_prompt ) ) ), 			'generationConfig'  => array( 'temperature' => $this->temperature, 'responseMimeType' => 'application/json' ), 		);  		$result = $this->http_post_json( $url, array(), $body );  		if ( ! $result['ok'] ) { 			return array( 'ok' => false, 'error' => $result['error'], 'retryable' => $result['retryable'] ?? true ); 		}  		$data          = json_decode( $result['body'], true ); 		$finish_reason = $data['candidates'][0]['finishReason'] ?? ''; 		$raw_text      = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';  		if ( '' === $raw_text ) { 			$reason_hint = $finish_reason ? "（finishReason: {$finish_reason}）" : ''; 			return array( 'ok' => false, 'error' => "Gemini 回應內容為空{$reason_hint}：" . substr( $result['body'], 0, 300 ) ); 		}  		return $this->parse_translations( $raw_text, count( $texts ) ); 	}  	public function verify_connection(): array { 		if ( empty( $this->api_key ) ) { 			return array( 'ok' => false, 'message' => 'Gemini API 金鑰未設定。' ); 		} 		if ( empty( $this->model ) ) { 			return array( 'ok' => false, 'message' => 'Gemini 模型代碼未設定。' ); 		}  		$url      = sprintf( 'https://generativelanguage.googleapis.com/v1beta/models/%s?key=%s', rawurlencode( $this->model ), rawurlencode( $this->api_key ) ); 		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );  		if ( is_wp_error( $response ) ) { 			return array( 'ok' => false, 'message' => '連線失敗：' . $response->get_error_message() ); 		}  		$code = wp_remote_retrieve_response_code( $response ); 		$body = wp_remote_retrieve_body( $response );  		if ( $code >= 200 && $code < 300 ) { 			$data = json_decode( $body, true ); 			$name = $data['displayName'] ?? $this->model; 			return array( 'ok' => true, 'message' => "驗證成功：模型「{$name}」可正常使用。" ); 		} 		if ( 404 === $code ) { 			return array( 'ok' => false, 'message' => "模型代碼「{$this->model}」不存在或已下架，請點擊「查詢官方最新模型清單」重新確認正確代碼。" ); 		} 		if ( 400 === $code || 403 === $code ) { 			return array( 'ok' => false, 'message' => 'API 金鑰可能無效或權限不足：' . substr( $body, 0, 200 ) ); 		}  		return array( 'ok' => false, 'message' => "驗證失敗（HTTP {$code}）：" . substr( $body, 0, 200 ) ); 	} }  class WU_AIT_Claude_Provider extends WU_AIT_Abstract_Provider {  	public function translate_batch( array $texts, string $target_lang, string $source_lang = 'zh-TW' ): array { 		if ( empty( $this->api_key ) ) { 			return array( 'ok' => false, 'error' => 'Anthropic Claude API 金鑰未設定，請到「設定 → Wu AI 翻譯 → 供應商與 API 金鑰」填入金鑰並儲存。', 'retryable' => false ); 		} 		if ( empty( $this->model ) ) { 			return array( 'ok' => false, 'error' => 'Claude 模型代碼未設定', 'retryable' => false ); 		}  		$system_prompt = $this->build_system_prompt( $target_lang, $source_lang ); 		$user_payload  = $this->build_user_payload( $texts ); 		$url           = 'https://api.anthropic.com/v1/messages';  		$body = array( 			'model'       => $this->model, 			'max_tokens'  => 4096, 			'temperature' => $this->temperature, 			'system'      => $system_prompt, 			'messages'    => array( array( 'role' => 'user', 'content' => $user_payload ) ), 		);  		$headers = array( 'x-api-key' => $this->api_key, 'anthropic-version' => '2023-06-01' ); 		$result  = $this->http_post_json( $url, $headers, $body );  		if ( ! $result['ok'] ) { 			return array( 'ok' => false, 'error' => $result['error'], 'retryable' => $result['retryable'] ?? true ); 		}  		$data     = json_decode( $result['body'], true ); 		$raw_text = $data['content'][0]['text'] ?? '';  		if ( '' === $raw_text ) { 			return array( 'ok' => false, 'error' => 'Claude 回應內容為空：' . substr( $result['body'], 0, 300 ) ); 		}  		return $this->parse_translations( $raw_text, count( $texts ) ); 	}  	public function verify_connection(): array { 		if ( empty( $this->api_key ) ) { 			return array( 'ok' => false, 'message' => 'Claude API 金鑰未設定。' ); 		} 		if ( empty( $this->model ) ) { 			return array( 'ok' => false, 'message' => 'Claude 模型代碼未設定。' ); 		}  		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array( 			'timeout' => 20, 			'headers' => array( 'Content-Type' => 'application/json', 'x-api-key' => $this->api_key, 'anthropic-version' => '2023-06-01' ), 			'body'    => wp_json_encode( array( 'model' => $this->model, 'max_tokens' => 8, 'messages' => array( array( 'role' => 'user', 'content' => 'ping' ) ) ) ), 		) );  		if ( is_wp_error( $response ) ) { 			return array( 'ok' => false, 'message' => '連線失敗：' . $response->get_error_message() ); 		}  		$code = wp_remote_retrieve_response_code( $response ); 		$body = wp_remote_retrieve_body( $response );  		if ( $code >= 200 && $code < 300 ) { 			return array( 'ok' => true, 'message' => "驗證成功：模型「{$this->model}」可正常使用。" ); 		} 		if ( 404 === $code || ( 400 === $code && false !== stripos( $body, 'model' ) ) ) { 			return array( 'ok' => false, 'message' => "模型代碼「{$this->model}」不存在，請點擊「查詢官方最新模型清單」重新確認正確代碼。" ); 		} 		if ( 401 === $code ) { 			return array( 'ok' => false, 'message' => 'API 金鑰無效，請確認金鑰是否正確。' ); 		}  		return array( 'ok' => false, 'message' => "驗證失敗（HTTP {$code}）：" . substr( $body, 0, 200 ) ); 	} }  class WU_AIT_Perplexity_Provider extends WU_AIT_Abstract_Provider {  	public function translate_batch( array $texts, string $target_lang, string $source_lang = 'zh-TW' ): array { 		if ( empty( $this->api_key ) ) { 			return array( 'ok' => false, 'error' => 'Perplexity API 金鑰未設定，請到「設定 → Wu AI 翻譯 → 供應商與 API 金鑰」填入金鑰並儲存。', 'retryable' => false ); 		} 		if ( empty( $this->model ) ) { 			return array( 'ok' => false, 'error' => 'Perplexity 模型代碼未設定', 'retryable' => false ); 		}  		$system_prompt = $this->build_system_prompt( $target_lang, $source_lang ); 		$user_payload  = $this->build_user_payload( $texts ); 		$url           = 'https://api.perplexity.ai/chat/completions';  		$body = array( 			'model'       => $this->model, 			'temperature' => $this->temperature, 			'messages'    => array( array( 'role' => 'system', 'content' => $system_prompt ), array( 'role' => 'user', 'content' => $user_payload ) ), 		);  		$headers = array( 'Authorization' => 'Bearer ' . $this->api_key ); 		$result  = $this->http_post_json( $url, $headers, $body );  		if ( ! $result['ok'] ) { 			return array( 'ok' => false, 'error' => $result['error'], 'retryable' => $result['retryable'] ?? true ); 		}  		$data     = json_decode( $result['body'], true ); 		$raw_text = $data['choices'][0]['message']['content'] ?? '';  		if ( '' === $raw_text ) { 			return array( 'ok' => false, 'error' => 'Perplexity 回應內容為空：' . substr( $result['body'], 0, 300 ) ); 		}  		return $this->parse_translations( $raw_text, count( $texts ) ); 	}  	public function verify_connection(): array { 		if ( empty( $this->api_key ) ) { 			return array( 'ok' => false, 'message' => 'Perplexity API 金鑰未設定。' ); 		} 		if ( empty( $this->model ) ) { 			return array( 'ok' => false, 'message' => 'Perplexity 模型代碼未設定。' ); 		}  		$response = wp_remote_post( 'https://api.perplexity.ai/chat/completions', array( 			'timeout' => 20, 			'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->api_key ), 			'body'    => wp_json_encode( array( 'model' => $this->model, 'max_tokens' => 8, 'messages' => array( array( 'role' => 'user', 'content' => 'ping' ) ) ) ), 		) );  		if ( is_wp_error( $response ) ) { 			return array( 'ok' => false, 'message' => '連線失敗：' . $response->get_error_message() ); 		}  		$code = wp_remote_retrieve_response_code( $response ); 		$body = wp_remote_retrieve_body( $response );  		if ( $code >= 200 && $code < 300 ) { 			return array( 'ok' => true, 'message' => "驗證成功：模型「{$this->model}」可正常使用。" ); 		} 		if ( 400 === $code || 404 === $code ) { 			return array( 'ok' => false, 'message' => "模型代碼「{$this->model}」不存在，請點擊「查詢官方最新模型清單」重新確認正確代碼。" ); 		} 		if ( 401 === $code ) { 			return array( 'ok' => false, 'message' => 'API 金鑰無效，請確認金鑰是否正確。' ); 		}  		return array( 'ok' => false, 'message' => "驗證失敗（HTTP {$code}）：" . substr( $body, 0, 200 ) ); 	} }  class WU_AIT_OpenAI_Provider extends WU_AIT_Abstract_Provider {  	public function translate_batch( array $texts, string $target_lang, string $source_lang = 'zh-TW' ): array { 		if ( empty( $this->api_key ) ) { 			return array( 'ok' => false, 'error' => 'OpenAI API 金鑰未設定，請到「設定 → Wu AI 翻譯 → 供應商與 API 金鑰」填入金鑰並儲存。', 'retryable' => false ); 		} 		if ( empty( $this->model ) ) { 			return array( 'ok' => false, 'error' => 'OpenAI 模型代碼未設定', 'retryable' => false ); 		}  		$system_prompt = $this->build_system_prompt( $target_lang, $source_lang ); 		$user_payload  = $this->build_user_payload( $texts ); 		$url           = 'https://api.openai.com/v1/chat/completions';  		$body = array( 			'model'           => $this->model, 			'temperature'     => $this->temperature, 			'response_format' => array( 'type' => 'json_object' ), 			'messages'        => array( array( 'role' => 'system', 'content' => $system_prompt ), array( 'role' => 'user', 'content' => $user_payload ) ), 		);  		$headers = array( 'Authorization' => 'Bearer ' . $this->api_key ); 		$result  = $this->http_post_json( $url, $headers, $body );  		if ( ! $result['ok'] ) { 			return array( 'ok' => false, 'error' => $result['error'], 'retryable' => $result['retryable'] ?? true ); 		}  		$data     = json_decode( $result['body'], true ); 		$raw_text = $data['choices'][0]['message']['content'] ?? '';  		if ( '' === $raw_text ) { 			return array( 'ok' => false, 'error' => 'OpenAI 回應內容為空：' . substr( $result['body'], 0, 300 ) ); 		}  		return $this->parse_translations( $raw_text, count( $texts ) ); 	}  	public function verify_connection(): array { 		if ( empty( $this->api_key ) ) { 			return array( 'ok' => false, 'message' => 'OpenAI API 金鑰未設定。' ); 		} 		if ( empty( $this->model ) ) { 			return array( 'ok' => false, 'message' => 'OpenAI 模型代碼未設定。' ); 		}  		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array( 			'timeout' => 20, 			'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->api_key ), 			'body'    => wp_json_encode( array( 'model' => $this->model, 'max_tokens' => 8, 'messages' => array( array( 'role' => 'user', 'content' => 'ping' ) ) ) ), 		) );  		if ( is_wp_error( $response ) ) { 			return array( 'ok' => false, 'message' => '連線失敗：' . $response->get_error_message() ); 		}  		$code = wp_remote_retrieve_response_code( $response ); 		$body = wp_remote_retrieve_body( $response );  		if ( $code >= 200 && $code < 300 ) { 			return array( 'ok' => true, 'message' => "驗證成功：模型「{$this->model}」可正常使用。" ); 		} 		if ( 404 === $code || ( 400 === $code && false !== stripos( $body, 'model' ) ) ) { 			return array( 'ok' => false, 'message' => "模型代碼「{$this->model}」不存在，請點擊「查詢官方最新模型清單」重新確認正確代碼。" ); 		} 		if ( 401 === $code ) { 			return array( 'ok' => false, 'message' => 'API 金鑰無效，請確認金鑰是否正確。' ); 		}  		return array( 'ok' => false, 'message' => "驗證失敗（HTTP {$code}）：" . substr( $body, 0, 200 ) ); 	} }  class WU_AIT_Provider_Factory {  	public static function make( ?array $settings = null, ?string $override_provider = null ): ?WU_AIT_Provider_Interface { 		$settings = $settings ?: wu_ait_get_settings(); 		$provider = $override_provider ?: $settings['provider'];  		switch ( $provider ) { 			case 'gemini': 				return new WU_AIT_Gemini_Provider( $settings['api_key_gemini'], $settings['model_gemini'], (float) $settings['temperature'], $settings['custom_glossary'], $settings['extra_prompt'] ); 			case 'claude': 				return new WU_AIT_Claude_Provider( $settings['api_key_claude'], $settings['model_claude'], (float) $settings['temperature'], $settings['custom_glossary'], $settings['extra_prompt'] ); 			case 'perplexity': 				return new WU_AIT_Perplexity_Provider( $settings['api_key_perplexity'], $settings['model_perplexity'], (float) $settings['temperature'], $settings['custom_glossary'], $settings['extra_prompt'] ); 			case 'openai': 				return new WU_AIT_OpenAI_Provider( $settings['api_key_openai'], $settings['model_openai'], (float) $settings['temperature'], $settings['custom_glossary'], $settings['extra_prompt'] ); 			default: 				return null; 		} 	}  	public static function get_provider_labels(): array { 		return array( 'gemini' => 'Google Gemini', 'claude' => 'Anthropic Claude', 'perplexity' => 'Perplexity', 'openai' => 'OpenAI ChatGPT' ); 	}  	public static function get_model_reference_links(): array { 		return array( 			'gemini'     => 'https://ai.google.dev/gemini-api/docs/models', 			'claude'     => 'https://docs.anthropic.com/en/docs/about-claude/models/overview', 			'perplexity' => 'https://docs.perplexity.ai/docs/sonar/models', 			'openai'     => 'https://platform.openai.com/docs/models', 		); 	}  	public static function get_model_placeholders(): array { 		return array( 			'gemini'     => '例如 gemini-3.8-flash（純代碼，勿加 models/ 前綴）', 			'claude'     => '例如 claude-sonnet-4-5、claude-haiku-4-5', 			'perplexity' => '例如 sonar、sonar-pro、sonar-reasoning-pro', 			'openai'     => '例如 gpt-4o-mini、gpt-4o、gpt-5', 		); 	} }  /**  * ------------------------------------------------------------------  *  翻譯服務  * ------------------------------------------------------------------  */ class WU_AIT_Translation_Service {  	public static function translate_texts( array $texts, string $target_lang, string $source_lang = 'zh-TW' ): array { 		$settings = wu_ait_get_settings(); 		$texts    = array_values( array_unique( array_filter( $texts, function ( $t ) { 			return is_string( $t ) && trim( $t ) !== ''; 		} ) ) );  		if ( empty( $texts ) ) { 			return array( 'ok' => true, 'translations' => array() ); 		}  		$final_map    = array(); 		$to_translate = array();  		if ( ! empty( $settings['cache_enabled'] ) ) { 			foreach ( $texts as $text ) { 				$cache_key = self::cache_key( $text, $target_lang, $settings['provider'] ); 				$cached    = get_transient( $cache_key ); 				if ( false !== $cached ) { 					$final_map[ $text ] = $cached; 				} else { 					$to_translate[] = $text; 				} 			} 		} else { 			$to_translate = $texts; 		}  		if ( empty( $to_translate ) ) { 			return array( 'ok' => true, 'translations' => $final_map ); 		}  		$provider = WU_AIT_Provider_Factory::make( $settings ); 		if ( ! $provider ) { 			return array( 'ok' => false, 'error' => '未知的 AI 供應商設定：' . $settings['provider'] ); 		}  		$batch_size = max( 1, (int) $settings['batch_size'] ); 		$chunks     = array_chunk( $to_translate, $batch_size ); 		$fail_count = 0;  		foreach ( $chunks as $chunk_index => $chunk ) { 			$result = $provider->translate_batch( $chunk, $target_lang, $source_lang );  			if ( ! $result['ok'] && ( $result['retryable'] ?? true ) && ! empty( $settings['fallback_provider'] ) && $settings['fallback_provider'] !== $settings['provider'] ) { 				wu_ait_add_log( sprintf( '主要供應商（%s）暫時無法使用，改用備援供應商（%s）重試本批次。', $settings['provider'], $settings['fallback_provider'] ), 'warning' ); 				$fallback = WU_AIT_Provider_Factory::make( $settings, $settings['fallback_provider'] ); 				if ( $fallback ) { 					$result = $fallback->translate_batch( $chunk, $target_lang, $source_lang ); 				} 			}  			if ( ! $result['ok'] ) { 				$fail_count++; 				wu_ait_add_log( sprintf( '批次 %d/%d 翻譯失敗：%s', $chunk_index + 1, count( $chunks ), $result['error'] ), 'error' ); 				continue; 			}  			foreach ( $chunk as $i => $original ) { 				$translated = $result['translations'][ $i ] ?? ''; 				if ( '' === trim( (string) $translated ) ) { 					continue; 				} 				$final_map[ $original ] = $translated;  				if ( ! empty( $settings['cache_enabled'] ) ) { 					$cache_key = self::cache_key( $original, $target_lang, $settings['provider'] ); 					set_transient( $cache_key, $translated, DAY_IN_SECONDS ); 				} 			} 		}  		if ( $fail_count > 0 && empty( $final_map ) ) { 			return array( 'ok' => false, 'error' => sprintf( '全部 %d 個批次皆翻譯失敗，請查看日誌了解詳細原因（常見原因：API 金鑰錯誤、模型代碼錯誤、額度用盡）。', count( $chunks ) ), 'translations' => array() ); 		}  		wu_ait_add_log( sprintf( '成功翻譯 %d 筆字串至 %s（供應商：%s，失敗批次：%d/%d）。', count( $final_map ), $target_lang, $settings['provider'], $fail_count, count( $chunks ) ) );  		return array( 'ok' => true, 'translations' => $final_map, 'failed_batches' => $fail_count, 'total_batches' => count( $chunks ) ); 	}  	protected static function cache_key( string $text, string $target_lang, string $provider ): string { 		return 'wu_ait_' . md5( $provider . '|' . $target_lang . '|' . $text ); 	}  	public static function flush_cache(): int { 		global $wpdb; 		return (int) $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wu_ait_%' OR option_name LIKE '_transient_timeout_wu_ait_%'" ); 	} }  /**  * ------------------------------------------------------------------  *  探索服務  * ------------------------------------------------------------------  */ class WU_AIT_Discovery_Service {
	const OPTION_KEY  = 'wu_ait_discovered_strings';
	const MAX_ENTRIES = 20000;

	public static function discover_from_post( int $post_id, bool $include_dictionary = true ): array {
    $segments = WU_AIT_TranslatePress_Bridge::get_translatable_segments_for_post( $post_id );

    if ( $include_dictionary ) {
        $dictionary_segments = WU_AIT_TranslatePress_Bridge::get_dictionary_originals_for_post( $post_id );
        if ( ! empty( $dictionary_segments ) ) {
            $segments = array_merge( $segments, $dictionary_segments );
        }
    }

    $segments = WU_AIT_TranslatePress_Bridge::filter_clean_candidates( $segments );

    // 單頁重新掃描時，先移除這個 post_id 舊紀錄，避免頁面刪掉的文字繼續被匯出。
    $store = get_option( self::OPTION_KEY, array() );
    $changed = false;
    foreach ( (array) $store as $key => $row ) {
        if ( (int) ( $row['post_id'] ?? 0 ) === $post_id ) {
            unset( $store[ $key ] );
            $changed = true;
        }
    }
    if ( $changed ) {
        update_option( self::OPTION_KEY, $store, false );
    }

    if ( ! empty( $segments ) ) {
        self::record_segments( $segments, $post_id );
    }

    return $segments;
}

	public static function discover_sitewide(): array {
    // v1.9.6：每次全站掃描都重建清單，避免已刪除/已修改的舊文字繼續留在 CSV。
    $cleaned_invalid = self::cleanup_invalid_entries();
    update_option( self::OPTION_KEY, array(), false );

    $post_types = get_post_types( array( 'public' => true ), 'names' );
    $post_types = array_values( array_diff( (array) $post_types, array( 'attachment' ) ) );
    if ( empty( $post_types ) ) {
        $post_types = array( 'post', 'page' );
    }

    $query = new WP_Query( array(
        'post_type'              => $post_types,
        'post_status'            => 'publish',
        'posts_per_page'         => -1,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ) );

    $total_posts      = count( $query->posts );
    $visible_count    = 0;
    $dictionary_count = 0;

    foreach ( $query->posts as $post_id ) {
        $visible = WU_AIT_TranslatePress_Bridge::get_translatable_segments_for_post( (int) $post_id );
        $dict    = WU_AIT_TranslatePress_Bridge::get_dictionary_originals_for_post( (int) $post_id );
        $merged  = WU_AIT_TranslatePress_Bridge::filter_clean_candidates( array_merge( $visible, $dict ) );

        $visible_count    += count( $visible );
        $dictionary_count += count( $dict );

        if ( ! empty( $merged ) ) {
            self::record_segments( $merged, (int) $post_id );
        }
    }

    // 重要：不再把整張 TranslatePress 字典無條件灌入探索清單。
    // 字典可能包含舊頁面、已刪除字串或歷史 translation block；只保留「目前頁面確實可見 / 可比對」者。
    return array(
        'scanned_posts'       => $total_posts,
        'total_discovered'    => self::count_discovered(),
        'this_run_segments'   => $visible_count,
        'dictionary_segments' => $dictionary_count,
        'cleaned_invalid'     => $cleaned_invalid,
        'post_types'          => $post_types,
    );
}

	public static function record_external_segments( array $segments, int $post_id = 0 ): void {
		self::record_segments( $segments, $post_id );
	}

	protected static function record_segments( array $segments, int $post_id = 0 ): void {
    $store = get_option( self::OPTION_KEY, array() );
    $now   = current_time( 'mysql' );

    foreach ( $segments as $text ) {
        $text = is_string( $text ) ? trim( $text ) : '';
        if ( ! WU_AIT_TranslatePress_Bridge::is_clean_translatable_candidate( $text ) ) {
            continue;
        }

        $key = md5( $text );
        if ( ! isset( $store[ $key ] ) ) {
            $store[ $key ] = array(
                'original'   => $text,
                'post_id'    => $post_id,
                'first_seen' => $now,
                'last_seen'  => $now,
            );
        } else {
            $store[ $key ]['last_seen'] = $now;
            if ( $post_id > 0 && empty( $store[ $key ]['post_id'] ) ) {
                $store[ $key ]['post_id'] = $post_id;
            }
        }
    }

    if ( count( $store ) > self::MAX_ENTRIES ) {
        uasort( $store, function ( $a, $b ) {
            return strcmp( $a['last_seen'], $b['last_seen'] );
        } );
        $store = array_slice( $store, count( $store ) - self::MAX_ENTRIES, null, true );
    }

    update_option( self::OPTION_KEY, $store, false );
}

	public static function get_all_discovered(): array {
    $store  = get_option( self::OPTION_KEY, array() );
    $result = array();

    foreach ( (array) $store as $row ) {
        $text = isset( $row['original'] ) && is_string( $row['original'] ) ? trim( $row['original'] ) : '';
        if ( WU_AIT_TranslatePress_Bridge::is_clean_translatable_candidate( $text ) ) {
            $result[] = $text;
        }
    }

    return array_values( array_unique( $result ) );
}

	public static function get_discovered_for_post( int $post_id ): array {
    $store  = get_option( self::OPTION_KEY, array() );
    $result = array();

    foreach ( (array) $store as $row ) {
        if ( (int) ( $row['post_id'] ?? 0 ) !== $post_id ) {
            continue;
        }
        $text = isset( $row['original'] ) && is_string( $row['original'] ) ? trim( $row['original'] ) : '';
        if ( WU_AIT_TranslatePress_Bridge::is_clean_translatable_candidate( $text ) ) {
            $result[] = $text;
        }
    }

    return array_values( array_unique( $result ) );
}


    /**
     * 清掉舊版探索紀錄中已知不是人類可見翻譯文字的項目。
     * 更新外掛後即使使用者沒有先按「清除已探索字串」，匯出也不會再帶出垃圾資料。
     */
    public static function cleanup_invalid_entries(): int {
        $store   = get_option( self::OPTION_KEY, array() );
        $cleaned = 0;

        foreach ( (array) $store as $key => $row ) {
            $text = isset( $row['original'] ) && is_string( $row['original'] ) ? trim( $row['original'] ) : '';
            if ( ! WU_AIT_TranslatePress_Bridge::is_clean_translatable_candidate( $text ) ) {
                unset( $store[ $key ] );
                $cleaned++;
            }
        }

        if ( $cleaned > 0 ) {
            update_option( self::OPTION_KEY, $store, false );
        }

        return $cleaned;
    }

	public static function count_discovered(): int {
		return count( get_option( self::OPTION_KEY, array() ) );
	}

	public static function clear_all(): void {
		update_option( self::OPTION_KEY, array(), false );
	}
}

/**  * ------------------------------------------------------------------  *  串接 TranslatePress  * ------------------------------------------------------------------  */ class WU_AIT_TranslatePress_Bridge {
	protected static $last_write_report = array();
	protected static $frontend_html_cache = array();
  	public static function get_target_languages(): array { 		if ( ! function_exists( 'trp_get_languages' ) && ! class_exists( 'TRP_Translate_Press' ) ) { 			return array(); 		} 		$trp          = TRP_Translate_Press::get_trp_instance(); 		$settings_obj = $trp->get_component( 'settings' ); 		$settings     = $settings_obj->get_settings();  		$published = isset( $settings['publish-languages'] ) ? (array) $settings['publish-languages'] : array(); 		$default   = $settings['default-language'] ?? '';  		return array_values( array_diff( $published, array( $default ) ) ); 	}  	public static function get_default_language(): string { 		if ( ! class_exists( 'TRP_Translate_Press' ) ) { 			return get_locale(); 		} 		$trp          = TRP_Translate_Press::get_trp_instance(); 		$settings_obj = $trp->get_component( 'settings' ); 		$settings     = $settings_obj->get_settings(); 		return $settings['default-language'] ?? get_locale(); 	}  	public static function dictionary_table( string $target_lang ): string {
		global $wpdb;

		// 優先使用 TranslatePress 自己的 TRP_Query 產生資料表名稱，避免版本差異。
		if ( class_exists( 'TRP_Translate_Press' ) ) {
			try {
				$trp   = TRP_Translate_Press::get_trp_instance();
				$query = $trp->get_component( 'query' );
				if ( $query && method_exists( $query, 'get_table_name' ) ) {
					return (string) $query->get_table_name( $target_lang, self::get_default_language() );
				}
			} catch ( Throwable $e ) {
				wu_ait_add_log( '取得 TranslatePress 字典表名稱時發生例外：' . $e->getMessage(), 'warning' );
			}
		}

		// Fallback：TranslatePress 正規字典表格式為 trp_dictionary_{來源語言}_{目標語言}。
		$source = strtolower( str_replace( '-', '_', self::get_default_language() ) );
		$target = strtolower( str_replace( '-', '_', $target_lang ) );

		return $wpdb->prefix . 'trp_dictionary_' . $source . '_' . $target;
	}

	public static function dictionary_table_exists( string $target_lang ): bool {
		global $wpdb;
		$table = self::dictionary_table( $target_lang );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	protected static function ensure_dictionary_table( string $target_lang ): bool {
		if ( self::dictionary_table_exists( $target_lang ) ) {
			return true;
		}

		if ( class_exists( 'TRP_Translate_Press' ) ) {
			try {
				$trp   = TRP_Translate_Press::get_trp_instance();
				$query = $trp->get_component( 'query' );
				if ( $query && method_exists( $query, 'check_table' ) ) {
					$query->check_table( self::get_default_language(), $target_lang );
				}
			} catch ( Throwable $e ) {
				wu_ait_add_log( '建立 TranslatePress 字典表時發生例外：' . $e->getMessage(), 'error' );
			}
		}

		return self::dictionary_table_exists( $target_lang );
	}

	public static function get_last_write_report(): array {
		return is_array( self::$last_write_report ) ? self::$last_write_report : array();
	}

	public static function extract_translatable_segments( string $html ): array {
    $segments = array();
    if ( '' === trim( $html ) ) {
        return array();
    }

    // 完整頁面只取 body；文章片段則包成 root 後解析。
    if ( preg_match( '/<body\b[^>]*>(.*)<\/body>/is', $html, $m ) ) {
        $html = $m[1];
    }

    // 先移除明確非可見內容，避免 CSS / JS / JSON-LD / SVG 被當成文字。
    $html = preg_replace( '/<(script|style|noscript|template|svg|canvas)\b[^>]*>.*?<\/\1>/is', '', $html );

    if ( class_exists( 'DOMDocument' ) ) {
        $previous = libxml_use_internal_errors( true );
        $dom      = new DOMDocument( '1.0', 'UTF-8' );
        $wrapped  = '<?xml encoding="utf-8" ?><div id="wu-ait-root">' . $html . '</div>';
        $loaded   = @$dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

        if ( $loaded ) {
            $xpath = new DOMXPath( $dom );

            // A. 真正的可見文字節點。
            $nodes = $xpath->query( '//*[@id="wu-ait-root"]//text()[normalize-space()]' );
            if ( $nodes ) {
                foreach ( $nodes as $node ) {
                    if ( self::dom_node_is_non_translatable( $node ) ) {
                        continue;
                    }
                    $candidate = self::normalize_translatable_candidate( (string) $node->nodeValue );
                    if ( self::is_translatable_candidate( $candidate ) ) {
                        $segments[] = $candidate;
                    }
                }
            }

            // B. 常見「一個視覺翻譯單位」的完整文字。
            // 可抓到 10GB + <span>備援空間</span> 這種被 inline tag 拆開的標題。
            $unit_query = '//*[@id="wu-ait-root"]//*[self::p or self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6 or self::button or self::a or self::label or self::li or self::summary or self::figcaption or self::th or self::td or self::strong or self::small]';
            $units = $xpath->query( $unit_query );
            if ( $units ) {
                foreach ( $units as $unit ) {
                    if ( self::dom_node_is_non_translatable( $unit ) ) {
                        continue;
                    }
                    $candidate = self::normalize_translatable_candidate( (string) $unit->textContent );
                    if ( mb_strlen( $candidate ) <= 1200 && self::is_translatable_candidate( $candidate ) ) {
                        $segments[] = $candidate;
                    }
                }
            }

            // C. 沒有 block-level 子元素的 div，也常被 Greenshift 用作文字容器。
            $divs = $xpath->query( '//*[@id="wu-ait-root"]//div[normalize-space()]' );
            if ( $divs ) {
                foreach ( $divs as $div ) {
                    if ( self::dom_node_is_non_translatable( $div ) ) {
                        continue;
                    }
                    $has_block_child = false;
                    foreach ( $div->childNodes as $child ) {
                        if ( XML_ELEMENT_NODE !== $child->nodeType ) {
                            continue;
                        }
                        if ( in_array( strtolower( $child->nodeName ), array( 'div','section','article','header','footer','main','nav','aside','ul','ol','li','table','p','h1','h2','h3','h4','h5','h6' ), true ) ) {
                            $has_block_child = true;
                            break;
                        }
                    }
                    if ( $has_block_child ) {
                        continue;
                    }
                    $candidate = self::normalize_translatable_candidate( (string) $div->textContent );
                    if ( mb_strlen( $candidate ) <= 500 && self::is_translatable_candidate( $candidate ) ) {
                        $segments[] = $candidate;
                    }
                }
            }

            // D. TranslatePress 也會處理部分可見 attributes。
            $attrs = $xpath->query( '//*[@id="wu-ait-root"]//*[@alt or @title or @placeholder or @aria-label or @data-label]' );
            if ( $attrs ) {
                foreach ( $attrs as $el ) {
                    if ( self::dom_node_is_non_translatable( $el ) ) {
                        continue;
                    }
                    foreach ( array( 'alt', 'title', 'placeholder', 'aria-label', 'data-label' ) as $attr ) {
                        if ( ! $el->hasAttribute( $attr ) ) {
                            continue;
                        }
                        $candidate = self::normalize_translatable_candidate( $el->getAttribute( $attr ) );
                        if ( self::is_translatable_candidate( $candidate ) ) {
                            $segments[] = $candidate;
                        }
                    }
                }
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
    }

    // DOMDocument 不可用或解析失敗時的保底。
    if ( empty( $segments ) ) {
        $parts = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_NO_EMPTY );
        foreach ( (array) $parts as $part ) {
            if ( '' === $part || '<' === $part[0] ) {
                continue;
            }
            $candidate = self::normalize_translatable_candidate( $part );
            if ( self::is_translatable_candidate( $candidate ) ) {
                $segments[] = $candidate;
            }
        }
    }

    return self::filter_clean_candidates( $segments );
}

	
protected static function dom_node_is_non_translatable( DOMNode $node ): bool {
    $current = ( $node instanceof DOMElement ) ? $node : $node->parentNode;

    while ( $current instanceof DOMElement ) {
        $tag = strtolower( $current->tagName );
        if ( in_array( $tag, array( 'script','style','noscript','template','svg','canvas','head','code','pre' ), true ) ) {
            return true;
        }

        if ( $current->hasAttribute( 'hidden' ) || 'true' === strtolower( trim( $current->getAttribute( 'aria-hidden' ) ) ) ) {
            return true;
        }

        $style = strtolower( $current->getAttribute( 'style' ) );
        if ( false !== strpos( $style, 'display:none' ) || false !== strpos( str_replace( ' ', '', $style ), 'display:none' ) || false !== strpos( str_replace( ' ', '', $style ), 'visibility:hidden' ) ) {
            return true;
        }

        $class = strtolower( $current->getAttribute( 'class' ) );
        if ( preg_match( '/(?:^|\s)(?:screen-reader-text|sr-only|visually-hidden|hidden)(?:\s|$)/', $class ) ) {
            return true;
        }

        if ( 'wu-ait-root' === $current->getAttribute( 'id' ) ) {
            break;
        }
        $current = $current->parentNode;
    }

    return false;
}

protected static function normalize_translatable_candidate( string $text ): string {
    $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $text = str_replace( array( "\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF" ), array( ' ', '', '' ), $text );
    $text = preg_replace( '/[\t ]+/u', ' ', $text );
    $text = preg_replace( '/\s*\R\s*/u', "\n", $text );
    return trim( (string) $text );
}

	public static function is_translatable_candidate( string $text ): bool {
    $text = self::normalize_translatable_candidate( $text );
    if ( '' === $text || mb_strlen( $text ) > 5000 ) {
        return false;
    }

    $plain = trim( wp_strip_all_tags( $text ) );
    if ( '' === $plain || ! preg_match( '/\p{L}/u', $plain ) ) {
        return false;
    }

    if ( preg_match( '~^(?:https?:|mailto:|tel:|data:|//)\S+$~i', $plain ) || preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/u', $plain ) ) {
        return false;
    }
    if ( preg_match( '/^[a-z]{2,3}(?:[-_][a-z]{2,4})?$/i', $plain ) || preg_match( '/^(?:true|false|null|undefined|none)$/i', $plain ) ) {
        return false;
    }

    // Builder / CSS / generated identifiers.
    if ( preg_match( '/^(?:gsbp|gspb|wp-block|elementor-element|brxe|et_pb|fl-node)[-_][a-z0-9_-]+$/i', $plain ) ) {
        return false;
    }
    if ( preg_match( '/^(?:[.#]|--)[a-z_][a-z0-9_-]{4,}$/i', $plain ) || preg_match( '/^[a-f0-9]{10,}$/i', $plain ) || preg_match( '/^[0-9]{6,}$/', $plain ) ) {
        return false;
    }
    if ( preg_match( '/^(?:[a-z0-9_-]+\.)+(?:css|js|map|woff2?|ttf|eot|svg|png|jpe?g|webp|avif)$/i', $plain ) ) {
        return false;
    }
    if ( preg_match( '/^[a-z][a-z0-9_-]{2,}:[a-z0-9_-]+$/i', $plain ) ) {
        return false;
    }

    // CSS values / functions / declarations.
    if ( preg_match( '/^#[0-9a-f]{3,8}$/i', $plain ) ) {
        return false;
    }
    if ( preg_match( '/^-?(?:\d+|\d*\.\d+)(?:px|r?em|vh|vw|vmin|vmax|%|s|ms|deg|fr)?(?:\s*[,\s]\s*-?(?:\d+|\d*\.\d+)(?:px|r?em|vh|vw|vmin|vmax|%|s|ms|deg|fr)?)*$/i', $plain ) ) {
        return false;
    }
    if ( preg_match( '/^(?:translate(?:3d|x|y|z)?|rotate(?:3d|x|y|z)?|scale(?:3d|x|y|z)?|skew(?:x|y)?|matrix(?:3d)?|cubic-bezier|steps|rgba?|hsla?|hwb|lab|lch|oklab|oklch|var|calc|min|max|clamp)\s*\(/i', $plain ) ) {
        return false;
    }
    if ( preg_match( '/^(?:opacity|transform|filter|color|background|background-color|border|box-shadow|transition|animation|display|position|overflow|visibility|width|height|margin|padding|gap|flex|grid)(?:\s*,\s*(?:opacity|transform|filter|color|background|background-color|border|box-shadow|transition|animation|display|position|overflow|visibility|width|height|margin|padding|gap|flex|grid))+$/i', $plain ) ) {
        return false;
    }
    if ( ( false !== strpos( $plain, '{' ) && false !== strpos( $plain, '}' ) && false !== strpos( $plain, ':' ) ) || ( substr_count( $plain, ';' ) >= 2 && substr_count( $plain, ':' ) >= 2 ) ) {
        return false;
    }
    if ( preg_match( '/^(?:[a-z-]+\s*:\s*[^;]+;?\s*){1,}$/i', $plain ) ) {
        return false;
    }

    if ( preg_match( '~^(?:application|text|image|font|video|audio)/[a-z0-9.+-]+$~i', $plain ) ) {
        return false;
    }
    if ( preg_match( '~^(?:[A-Za-z]:\\\\|/)(?:[^\s/]+/)+[^\s/]*$~', $plain ) ) {
        return false;
    }

    return true;
}

	

    public static function is_clean_translatable_candidate( string $text ): bool {
        return self::is_translatable_candidate( $text );
    }

    public static function filter_clean_candidates( array $segments ): array {
        $result = array();
        foreach ( $segments as $text ) {
            if ( ! is_string( $text ) ) {
                continue;
            }
            $text = self::normalize_translatable_candidate( $text );
            if ( self::is_translatable_candidate( $text ) ) {
                $result[] = $text;
            }
        }
        return array_values( array_unique( $result ) );
    }

protected static function extract_candidates_from_mixed_value( $value, int $depth = 0, string $context_key = '' ): array {
    if ( $depth > 10 ) {
        return array();
    }

    $segments = array();

    if ( is_object( $value ) ) {
        $value = get_object_vars( $value );
    }

    if ( is_array( $value ) ) {
        foreach ( $value as $key => $child ) {
            $key = is_string( $key ) ? $key : $context_key;

            // 明確技術/樣式欄位整棵略過，不再把 CSS、動畫、ID 掃成翻譯字串。
            if ( is_string( $key ) && preg_match( '/(?:^|_)(?:id|uid|class|classname|css|style|styles|selector|selectors|animation|transition|transform|filter|font|color|background|border|shadow|spacing|margin|padding|width|height|size|position|responsive|desktop|tablet|mobile|breakpoint|zindex|z_index|opacity|duration|delay|easing|keyframes|attributes?|settings?|options?|query|url|href|src|icon)(?:$|_)/i', $key ) ) {
                continue;
            }

            $segments = array_merge( $segments, self::extract_candidates_from_mixed_value( $child, $depth + 1, (string) $key ) );
        }
        return self::filter_clean_candidates( $segments );
    }

    if ( ! is_string( $value ) || '' === trim( $value ) ) {
        return array();
    }

    $raw = trim( $value );

    // 若是 serialized / JSON builder data，先解成結構再依欄位名稱挑文字。
    if ( 0 === $depth ) {
        $maybe_unserialized = maybe_unserialize( $raw );
        if ( $maybe_unserialized !== $raw && ( is_array( $maybe_unserialized ) || is_object( $maybe_unserialized ) ) ) {
            return self::extract_candidates_from_mixed_value( $maybe_unserialized, $depth + 1, $context_key );
        }

        $decoded = json_decode( $raw, true );
        if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
            return self::extract_candidates_from_mixed_value( $decoded, $depth + 1, $context_key );
        }
    }

    // HTML 本身的可見文字永遠可以掃；HTML attributes 仍由 extract_translatable_segments 限定白名單。
    if ( false !== strpos( $raw, '<' ) && false !== strpos( $raw, '>' ) ) {
        return self::extract_translatable_segments( $raw );
    }

    // 非 HTML 的 builder attr 只有「明確文字語意」欄位才接受。
    $is_text_key = ( 0 === $depth ) || preg_match( '/(?:text|content|title|heading|headline|subtitle|description|desc|label|caption|placeholder|message|button|quote|author|prefix|suffix|before|after|name|html|editor|paragraph|value)$/i', $context_key );
    if ( ! $is_text_key ) {
        return array();
    }

    $candidate = self::normalize_translatable_candidate( $raw );
    if ( self::is_translatable_candidate( $candidate ) ) {
        $segments[] = $candidate;
    }

    return $segments;
}

	protected static function extract_segments_from_blocks( array $blocks ): array {
    $segments = array();

    foreach ( $blocks as $block ) {
        // 1. innerHTML 是最可靠的 block 可見文字來源。
        if ( ! empty( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
            $segments = array_merge( $segments, self::extract_translatable_segments( $block['innerHTML'] ) );
        }

        // 2. 只從具文字語意的 attrs 抓取，不再遞迴接受所有屬性。
        if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
            $segments = array_merge( $segments, self::extract_candidates_from_mixed_value( $block['attrs'], 1, 'block' ) );
        }

        // 3. 遞迴 innerBlocks。
        if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
            $segments = array_merge( $segments, self::extract_segments_from_blocks( $block['innerBlocks'] ) );
        }
    }

    return self::filter_clean_candidates( $segments );
}

	
protected static function fetch_frontend_html_for_post( int $post_id ): string {
    if ( array_key_exists( $post_id, self::$frontend_html_cache ) ) {
        return (string) self::$frontend_html_cache[ $post_id ];
    }

    $url = get_permalink( $post_id );
    if ( ! $url || ! wp_http_validate_url( $url ) ) {
        self::$frontend_html_cache[ $post_id ] = '';
        return '';
    }

    $url = add_query_arg( 'wu_ait_scan', WU_AIT_VERSION, $url );
    $source_lang = str_replace( '_', '-', self::get_default_language() );
    $response = wp_remote_get( $url, array(
        'timeout'     => 12,
        'redirection' => 3,
        'headers'     => array(
            'User-Agent'      => 'Wu-AI-Translate-Scanner/' . WU_AIT_VERSION . '; ' . home_url( '/' ),
            'Accept-Language' => $source_lang . ',' . substr( $source_lang, 0, 2 ) . ';q=0.9',
            'Cache-Control'   => 'no-cache, no-store, max-age=0',
            'Pragma'          => 'no-cache',
        ),
    ) );

    if ( is_wp_error( $response ) ) {
        wu_ait_add_log( sprintf( '前台實際頁面掃描失敗 #%d：%s', $post_id, $response->get_error_message() ), 'warning' );
        self::$frontend_html_cache[ $post_id ] = '';
        return '';
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 400 ) {
        wu_ait_add_log( sprintf( '前台實際頁面掃描 #%d 回傳 HTTP %d，已改用文章內容掃描結果。', $post_id, $code ), 'warning' );
        self::$frontend_html_cache[ $post_id ] = '';
        return '';
    }

    $body = (string) wp_remote_retrieve_body( $response );
    self::$frontend_html_cache[ $post_id ] = $body;
    return $body;
}

protected static function fetch_frontend_segments_for_post( int $post_id ): array {
    $body = self::fetch_frontend_html_for_post( $post_id );
    if ( '' === $body ) {
        return array();
    }
    return self::extract_translatable_segments( $body );
}

	public static function get_translatable_segments_for_post( int $post_id ): array {
    return self::get_translatable_segments_for_post_without_dictionary( $post_id );
}

	
public static function get_dictionary_originals_for_language( string $target_lang ): array {
    global $wpdb;

    if ( '' === $target_lang || ! self::dictionary_table_exists( $target_lang ) ) {
        return array();
    }

    $table   = self::dictionary_table( $target_lang );
    $columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
    $where   = "original IS NOT NULL AND original <> ''";
    if ( is_array( $columns ) && in_array( 'block_type', $columns, true ) ) {
        $where .= ' AND (block_type IS NULL OR block_type <> 2)';
    }

    $rows = $wpdb->get_col( "SELECT original FROM `{$table}` WHERE {$where} ORDER BY id ASC LIMIT 50000" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    if ( $wpdb->last_error ) {
        wu_ait_add_log( '讀取 TranslatePress 已偵測字串失敗：' . $wpdb->last_error, 'warning' );
        $wpdb->last_error = '';
        return array();
    }

    return self::filter_clean_candidates( (array) $rows );
}

/**
 * 從 TranslatePress 字典中找出「確實屬於這一篇頁面」的 original。
 *
 * 為什麼需要這層：TranslatePress Translation Editor 已經能看到的字串，
 * 可能來自 dynamic block / Greenshift / reusable block，未必被單純 post_content 掃描抓到；
 * 但字典表本身沒有 post_id。這裡以頁面的原始內容、rendered HTML、前台 HTML、
 * 以及已知 builder content meta 做精準比對，只補「真的出現在此頁來源資料」的字串。
 */

protected static function normalize_dictionary_match_key( string $text ): string {
    $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $text = wp_strip_all_tags( $text );
    $text = str_replace( array( "\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF" ), ' ', $text );
    $text = preg_replace( '/\s+/u', ' ', $text );
    return trim( (string) $text );
}

public static function get_dictionary_originals_for_post( int $post_id, string $target_lang = '' ): array {
    $post = get_post( $post_id );
    if ( ! $post ) {
        return array();
    }

    $dictionary = ( '' !== $target_lang )
        ? self::get_dictionary_originals_for_language( $target_lang )
        : self::get_all_dictionary_originals();

    if ( empty( $dictionary ) ) {
        return array();
    }

    $frontend_html = self::fetch_frontend_html_for_post( $post_id );
    $rendered      = (string) apply_filters( 'the_content', (string) $post->post_content );
    $raw_corpus    = implode( "\n", array(
        (string) get_the_title( $post_id ),
        (string) $post->post_excerpt,
        (string) $post->post_content,
        $rendered,
        $frontend_html,
    ) );
    $raw_corpus = html_entity_decode( $raw_corpus, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

    $visible = self::get_translatable_segments_for_post_without_dictionary( $post_id );
    $visible_lookup = array();
    foreach ( $visible as $item ) {
        $key = self::normalize_dictionary_match_key( $item );
        if ( '' !== $key ) {
            $visible_lookup[ $key ] = true;
        }
    }

    $matched = array();
    foreach ( $dictionary as $original ) {
        $original = (string) $original;
        $key      = self::normalize_dictionary_match_key( $original );
        if ( '' === $key ) {
            continue;
        }

        // 1) 字典原文完整存在於目前頁面的 HTML / post_content。
        if ( false !== mb_strpos( $raw_corpus, $original ) ) {
            $matched[] = $original;
            continue;
        }

        // 2) 去除 HTML 後的視覺文字與目前頁面可見單位完全一致。
        if ( isset( $visible_lookup[ $key ] ) ) {
            $matched[] = $original;
            continue;
        }
    }

    return self::filter_clean_candidates( $matched );
}

/**
 * 頁面基礎掃描（不呼叫 dictionary），供 dictionary 比對避免遞迴。
 */
protected static function get_translatable_segments_for_post_without_dictionary( int $post_id ): array {
    $post = get_post( $post_id );
    if ( ! $post ) {
        return array();
    }

    $segments = array();
    $title = trim( get_the_title( $post_id ) );
    if ( self::is_translatable_candidate( $title ) ) {
        $segments[] = $title;
    }

    $excerpt = trim( (string) $post->post_excerpt );
    if ( '' !== $excerpt ) {
        $segments = array_merge( $segments, self::extract_translatable_segments( wpautop( $excerpt ) ) );
    }

    // 原始內容只抓實際文字節點，不讀 block attrs / post meta。
    $segments = array_merge( $segments, self::extract_translatable_segments( (string) $post->post_content ) );

    // Server-side render（shortcode / dynamic block）。
    $rendered = apply_filters( 'the_content', (string) $post->post_content );
    $segments = array_merge( $segments, self::extract_translatable_segments( (string) $rendered ) );

    // 最可靠來源：訪客實際取得的前台 HTML。
    $segments = array_merge( $segments, self::fetch_frontend_segments_for_post( $post_id ) );

    return self::filter_clean_candidates( $segments );
}

public static function get_all_dictionary_originals(): array {
    $all = array();
    foreach ( self::get_target_languages() as $target_lang ) {
        $all = array_merge( $all, self::get_dictionary_originals_for_language( $target_lang ) );
    }
    return self::filter_clean_candidates( $all );
}

	public static function filter_untranslated( array $segments, string $target_lang ): array {
    global $wpdb;

    $segments = self::filter_clean_candidates( $segments );
    if ( empty( $segments ) || ! self::dictionary_table_exists( $target_lang ) ) {
        return $segments;
    }

    $table = self::dictionary_table( $target_lang );
    $state = array();

    foreach ( array_chunk( $segments, 300 ) as $chunk ) {
        $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
        $sql = "SELECT original, translated, status, block_type FROM `{$table}` WHERE original IN ({$placeholders}) AND (block_type IS NULL OR block_type <> 2)";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ), ARRAY_A );
        foreach ( (array) $rows as $row ) {
            $o = (string) $row['original'];
            if ( ! isset( $state[ $o ] ) ) {
                $state[ $o ] = array( 'rows' => 0, 'blank' => false );
            }
            $state[ $o ]['rows']++;
            if ( '' === trim( (string) ( $row['translated'] ?? '' ) ) ) {
                $state[ $o ]['blank'] = true;
            }
        }
    }

    $result = array();
    foreach ( $segments as $original ) {
        if ( ! isset( $state[ $original ] ) || $state[ $original ]['rows'] < 1 || $state[ $original ]['blank'] ) {
            $result[] = $original;
        }
    }
    return array_values( array_unique( $result ) );
}  	public static function get_translation_map( string $target_lang, array $originals ): array {
    global $wpdb;

    $originals = array_values( array_unique( array_filter( array_map( 'strval', $originals ) ) ) );
    if ( empty( $originals ) || ! self::dictionary_table_exists( $target_lang ) ) {
        return array();
    }

    $table = self::dictionary_table( $target_lang );
    $map   = array();
    $prio  = array();

    foreach ( array_chunk( $originals, 300 ) as $chunk ) {
        $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
        $sql = "SELECT id, original, translated, status, block_type FROM `{$table}` WHERE original IN ({$placeholders}) AND translated IS NOT NULL AND translated <> '' AND (block_type IS NULL OR block_type <> 2)";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ), ARRAY_A );

        foreach ( (array) $rows as $row ) {
            $original   = (string) $row['original'];
            $translated = (string) $row['translated'];
            if ( '' === trim( $translated ) ) {
                continue;
            }
            $block_type = isset( $row['block_type'] ) ? (int) $row['block_type'] : 0;
            $priority   = ( 1 === $block_type ? 1000000 : 0 ) + ( (int) ( $row['status'] ?? 0 ) * 1000 ) + (int) $row['id'];
            if ( ! isset( $prio[ $original ] ) || $priority > $prio[ $original ] ) {
                $prio[ $original ] = $priority;
                $map[ $original ]  = $translated;
            }
        }
    }

    return $map;
}  	public static function get_progress_for_language( string $target_lang ): array {
    $discovered = WU_AIT_Discovery_Service::get_all_discovered();
    $total      = count( $discovered );
    if ( 0 === $total ) {
        return array( 'total' => 0, 'translated' => 0 );
    }
    $missing = self::filter_untranslated( $discovered, $target_lang );
    return array( 'total' => $total, 'translated' => max( 0, $total - count( $missing ) ) );
}  	/** 	 * 寫入前淨化：只移除明確的語言標記前綴（[EN]、(EN)、EN:）， 	 * 不會誤傷任何正常英文／其他語言句子（v1.9.1 修正重點）。 	 */ 	protected static function sanitize_translation_value( string $text ): string { 		$text = trim( $text ); 		$text = wu_ait_strip_language_prefix( $text ); 		return trim( $text ); 	}  	public static function upsert_translations_by_original( string $target_lang, array $original_to_translated ): int {
    global $wpdb;

    self::$last_write_report = array(
        'table'              => self::dictionary_table( $target_lang ),
        'attempted'          => 0,
        'written'            => 0,
        'inserted'           => 0,
        'updated'            => 0,
        'rows_updated'       => 0,
        'exact_matches'      => 0,
        'normalized_matches' => 0,
        'multi_row_matches'  => 0,
        'skipped'            => 0,
        'errors'             => array(),
    );

    if ( empty( $original_to_translated ) ) {
        return 0;
    }

    $table = self::dictionary_table( $target_lang );
    self::$last_write_report['table'] = $table;
    if ( ! self::ensure_dictionary_table( $target_lang ) ) {
        $msg = "TranslatePress 字典表 {$table} 不存在。請確認來源語言／目標語言已在 TranslatePress 正確設定並發佈。";
        self::$last_write_report['errors'][] = $msg;
        wu_ait_add_log( $msg, 'error' );
        return 0;
    }

    $columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
    if ( ! is_array( $columns ) || empty( $columns ) ) {
        $msg = '無法讀取 TranslatePress 字典表欄位：' . ( $wpdb->last_error ?: $table );
        self::$last_write_report['errors'][] = $msg;
        wu_ait_add_log( $msg, 'error' );
        return 0;
    }

    // 一次建立目前字典索引，解決同一 original 同時存在 regular / active translation block 的情況。
    $all_rows = $wpdb->get_results( "SELECT id, original, translated, status" . ( in_array( 'block_type', $columns, true ) ? ', block_type' : '' ) . " FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $exact_index      = array();
    $normalized_index = array();

    foreach ( (array) $all_rows as $row ) {
        $original = (string) $row['original'];
        $bt       = isset( $row['block_type'] ) ? (int) $row['block_type'] : 0;
        if ( 2 === $bt ) {
            continue; // deprecated block 不寫。
        }
        $exact_index[ $original ][] = $row;

        // 正規化 fallback 只允許 regular string，避免把純文字譯文誤塞進 HTML translation block。
        if ( 0 === $bt ) {
            $key = self::normalize_dictionary_match_key( $original );
            if ( '' !== $key ) {
                $normalized_index[ $key ][] = $row;
            }
        }
    }

    $written_strings = 0;
    foreach ( $original_to_translated as $original => $translated ) {
        $original   = (string) $original;
        $translated = self::sanitize_translation_value( (string) $translated );
        if ( '' === trim( $original ) || '' === trim( $translated ) ) {
            self::$last_write_report['skipped']++;
            continue;
        }

        self::$last_write_report['attempted']++;
        $rows = $exact_index[ $original ] ?? array();
        $mode = 'exact';

        if ( empty( $rows ) ) {
            $key  = self::normalize_dictionary_match_key( $original );
            $rows = ( '' !== $key && isset( $normalized_index[ $key ] ) ) ? $normalized_index[ $key ] : array();
            $mode = 'normalized';
        }

        if ( ! empty( $rows ) ) {
            $ids = array_values( array_unique( array_map( function ( $r ) { return (int) $r['id']; }, $rows ) ) );
            if ( count( $ids ) > 1 ) {
                self::$last_write_report['multi_row_matches']++;
            }

            $ok_rows = 0;
            foreach ( $ids as $id ) {
                $result = $wpdb->update(
                    $table,
                    array( 'translated' => $translated, 'status' => 1 ),
                    array( 'id' => $id ),
                    array( '%s', '%d' ),
                    array( '%d' )
                );
                if ( false !== $result ) {
                    $ok_rows++;
                    self::$last_write_report['rows_updated']++;
                } else {
                    $msg = '更新翻譯失敗：' . ( $wpdb->last_error ?: '未知資料庫錯誤' );
                    self::$last_write_report['errors'][] = $msg;
                    wu_ait_add_log( $msg . '｜ID：' . $id . '｜原文：' . mb_substr( $original, 0, 120 ), 'error' );
                    $wpdb->last_error = '';
                }
            }

            if ( $ok_rows > 0 ) {
                $written_strings++;
                self::$last_write_report['written']++;
                self::$last_write_report['updated']++;
                if ( 'exact' === $mode ) {
                    self::$last_write_report['exact_matches']++;
                } else {
                    self::$last_write_report['normalized_matches']++;
                }
            }
            continue;
        }

        // 完全不存在時才建立 regular string。不要憑空建立 active translation block。
        $data = array(
            'original'   => $original,
            'translated' => $translated,
            'status'     => 1,
        );
        $format = array( '%s', '%s', '%d' );
        if ( in_array( 'block_type', $columns, true ) ) {
            $data['block_type'] = 0;
            $format[] = '%d';
        }

        $result = $wpdb->insert( $table, $data, $format );
        if ( false !== $result ) {
            $written_strings++;
            self::$last_write_report['written']++;
            self::$last_write_report['inserted']++;
        } else {
            $msg = '新增翻譯失敗：' . ( $wpdb->last_error ?: '未知資料庫錯誤' );
            self::$last_write_report['errors'][] = $msg;
            wu_ait_add_log( $msg . '｜原文：' . mb_substr( $original, 0, 120 ), 'error' );
            $wpdb->last_error = '';
        }
    }

    return $written_strings;
}

	public static function get_translatable_posts( int $limit = 200 ): array { 		$query = new WP_Query( array( 			'post_type'      => array( 'post', 'page' ), 			'post_status'    => 'publish', 			'posts_per_page' => $limit, 			'orderby'        => 'modified', 			'order'          => 'DESC', 			'fields'         => 'ids', 		) );  		$items = array(); 		foreach ( $query->posts as $post_id ) { 			$items[] = array( 				'id'    => $post_id, 				'title' => get_the_title( $post_id ) ?: "（無標題 #{$post_id}）", 				'type'  => get_post_type( $post_id ), 			); 		} 		return $items; 	}  	public static function run_batch_sitewide( string $target_lang, int $limit = 100 ): array { 		$discovered = WU_AIT_Discovery_Service::get_all_discovered();  		if ( empty( $discovered ) ) { 			return array( 'ok' => true, 'translated_count' => 0, 'message' => '尚未探索到任何字串，請先到「批次翻譯」分頁點擊「立即掃描全站」按鈕。' ); 		}  		$to_translate = self::filter_untranslated( $discovered, $target_lang );  		if ( empty( $to_translate ) ) { 			return array( 'ok' => true, 'translated_count' => 0, 'fetched_count' => count( $discovered ), 'message' => '已探索字串全部翻譯完成，無需重新翻譯。' ); 		}  		$to_translate = array_slice( $to_translate, 0, $limit );  		$default_lang = self::get_default_language(); 		$result       = WU_AIT_Translation_Service::translate_texts( $to_translate, $target_lang, $default_lang );  		if ( ! $result['ok'] && empty( $result['translations'] ) ) { 			return array( 'ok' => false, 'error' => $result['error'] ?? '翻譯失敗' ); 		}  		$updated = self::upsert_translations_by_original( $target_lang, $result['translations'] );  		return array( 			'ok'               => true, 			'translated_count' => $updated, 			'fetched_count'    => count( $to_translate ), 			'failed_batches'   => $result['failed_batches'] ?? 0, 			'total_batches'    => $result['total_batches'] ?? 0, 			'scope'            => 'sitewide', 		); 	}  	public static function run_batch_for_post( int $post_id, string $target_lang ): array { 		$post = get_post( $post_id ); 		if ( ! $post ) { 			return array( 'ok' => false, 'error' => '找不到指定的頁面／文章（ID：' . $post_id . '）。' ); 		}  		$segments = WU_AIT_Discovery_Service::discover_from_post( $post_id );  		if ( empty( $segments ) ) { 			return array( 'ok' => true, 'translated_count' => 0, 'message' => '此頁面／文章沒有可翻譯的文字內容。' ); 		}  		$to_translate = self::filter_untranslated( $segments, $target_lang );  		if ( empty( $to_translate ) ) { 			return array( 'ok' => true, 'translated_count' => 0, 'fetched_count' => count( $segments ), 'message' => sprintf( '此頁面／文章共探索到 %d 段文字，全部已有翻譯，無需重新翻譯。', count( $segments ) ) ); 		}  		$default_lang = self::get_default_language(); 		$result       = WU_AIT_Translation_Service::translate_texts( $to_translate, $target_lang, $default_lang );  		if ( ! $result['ok'] && empty( $result['translations'] ) ) { 			return array( 'ok' => false, 'error' => $result['error'] ?? '翻譯失敗' ); 		}  		$updated = self::upsert_translations_by_original( $target_lang, $result['translations'] );  		return array( 			'ok'               => true, 			'translated_count' => $updated, 			'fetched_count'    => count( $to_translate ), 			'total_segments'   => count( $segments ), 			'failed_batches'   => $result['failed_batches'] ?? 0, 			'total_batches'    => $result['total_batches'] ?? 0, 			'scope'            => 'post', 			'post_id'          => $post_id, 			'post_title'       => get_the_title( $post_id ), 		); 	} }  /**  * ------------------------------------------------------------------  *  匯出／匯入服務  *  *  修正說明（v1.9.1）：改善 CSV 的 Excel 相容性。  *  - 換行符號統一使用 "\r\n"（Windows CRLF），避免 Excel 誤判整份內容為單一欄位。  *  - fputcsv 明確指定逗號分隔、雙引號包裹字元，避免部分 Excel 版本以系統地區設定  *    （如中文 Windows 常見的分號分隔）誤判分隔符號，造成欄位錯亂與亂碼。  *  - 保留 UTF-8 BOM，讓 Excel 識別檔案為 UTF-8 而非本機預設編碼（如 Big5）。  * ------------------------------------------------------------------  */ class WU_AIT_Export_Import_Service {  	public static function build_export_csv( string $target_lang, string $scope = 'sitewide', int $post_id = 0 ): array {
    WU_AIT_Discovery_Service::cleanup_invalid_entries();

    if ( 'post' === $scope && $post_id > 0 ) {
        // 每次匯出先重新掃這頁，避免「頁面已改但探索清單沒更新」。
        WU_AIT_Discovery_Service::discover_from_post( $post_id, true );
        $originals = WU_AIT_Discovery_Service::get_discovered_for_post( $post_id );

        $dictionary_for_post = WU_AIT_TranslatePress_Bridge::get_dictionary_originals_for_post( $post_id, $target_lang );
        if ( ! empty( $dictionary_for_post ) ) {
            WU_AIT_Discovery_Service::record_external_segments( $dictionary_for_post, $post_id );
            $originals = array_merge( $originals, $dictionary_for_post );
        }

        $originals = WU_AIT_TranslatePress_Bridge::filter_clean_candidates( $originals );
        $post_map  = array_fill_keys( $originals, $post_id );
    } else {
        // 全站匯出前自動重新掃描「目前可見頁面」，不再混入整張歷史字典。
        WU_AIT_Discovery_Service::discover_sitewide();
        $originals = WU_AIT_Discovery_Service::get_all_discovered();
        $post_map  = array();
        foreach ( get_option( WU_AIT_Discovery_Service::OPTION_KEY, array() ) as $row ) {
            if ( empty( $row['original'] ) ) {
                continue;
            }
            $post_map[ $row['original'] ] = (int) ( $row['post_id'] ?? 0 );
        }
    }

    if ( empty( $originals ) ) {
        return array( 'ok' => false, 'error' => '沒有可匯出的可見字串。請確認頁面可公開瀏覽，或先到「批次翻譯」重新掃描。' );
    }

    $translation_map = WU_AIT_TranslatePress_Bridge::get_translation_map( $target_lang, $originals );

    $rows   = array();
    $rows[] = array( '原文', '翻譯', '目標語言', '來源文章ID' );
    foreach ( $originals as $original ) {
        $rows[] = array(
            $original,
            $translation_map[ $original ] ?? '',
            $target_lang,
            (string) ( $post_map[ $original ] ?? '' ),
        );
    }

    return array(
        'ok'       => true,
        'csv'      => self::array_to_csv( $rows ),
        'count'    => count( $originals ),
        'filename' => sprintf( 'wu-ai-translate-export-%s-%s.csv', $target_lang, gmdate( 'Ymd-His' ) ),
    );
}  	protected static function array_to_csv( array $rows ): string {
		$fh = fopen( 'php://temp', 'w+' );
		if ( ! $fh ) {
			return '';
		}

		// UTF-8 BOM：Windows Excel 直接雙擊時較不容易把中文誤判成 Big5/ANSI。
		fwrite( $fh, "\xEF\xBB\xBF" );

		foreach ( $rows as $row ) {
			// 使用標準逗號 CSV；escape 設為空字串，避免非標準反斜線跳脫。
			fputcsv( $fh, $row, ',', '"', '' );
		}

		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh );

		// 統一為 Windows / Excel 最穩定的 CRLF。
		$csv = preg_replace( '/(?<!\r)\n/', "\r\n", $csv );
		return $csv;
	}

	protected static function convert_csv_to_utf8( string $content ): array {
		$encoding = 'UTF-8';

		if ( 0 === strncmp( $content, "\xEF\xBB\xBF", 3 ) ) {
			$content  = substr( $content, 3 );
			$encoding = 'UTF-8 BOM';
		} elseif ( 0 === strncmp( $content, "\xFF\xFE", 2 ) ) {
			$encoding = 'UTF-16LE';
			$content  = substr( $content, 2 );
			$content  = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $content, 'UTF-8', 'UTF-16LE' ) : iconv( 'UTF-16LE', 'UTF-8//IGNORE', $content );
		} elseif ( 0 === strncmp( $content, "\xFE\xFF", 2 ) ) {
			$encoding = 'UTF-16BE';
			$content  = substr( $content, 2 );
			$content  = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $content, 'UTF-8', 'UTF-16BE' ) : iconv( 'UTF-16BE', 'UTF-8//IGNORE', $content );
		} elseif ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$detected = function_exists( 'mb_detect_encoding' )
				? mb_detect_encoding( $content, array( 'BIG-5', 'CP950', 'SJIS-win', 'Windows-1252', 'ISO-8859-1' ), true )
				: false;

			if ( $detected ) {
				$encoding = $detected;
				$content  = mb_convert_encoding( $content, 'UTF-8', $detected );
			} elseif ( function_exists( 'iconv' ) ) {
				$converted = @iconv( 'CP950', 'UTF-8//IGNORE', $content );
				if ( false !== $converted && '' !== $converted ) {
					$encoding = 'CP950/Big5';
					$content  = $converted;
				}
			}
		}

		if ( ! is_string( $content ) ) {
			$content = '';
		}

		// AI 偶爾會把 CSV 包在 Markdown code block 中，匯入時自動清掉。
		$content = preg_replace( '/^\s*```(?:csv)?\s*\r?\n/i', '', $content );
		$content = preg_replace( '/\r?\n```\s*$/', '', $content );
		$content = preg_replace( '/^\xEF\xBB\xBF/', '', $content );
		$content = str_replace( array( "\r\n", "\r" ), "\n", $content );

		return array( 'content' => $content, 'encoding' => $encoding );
	}

	protected static function detect_csv_delimiter( string $content ): string {
		$lines = preg_split( '/\n/', $content );
		$first = '';
		foreach ( $lines as $line ) {
			if ( '' !== trim( $line ) ) {
				$first = trim( $line );
				break;
			}
		}

		if ( 0 === stripos( $first, 'sep=' ) ) {
			$declared = substr( $first, 4, 1 );
			if ( in_array( $declared, array( ',', ';', "\t" ), true ) ) {
				return $declared;
			}
		}

		$candidates = array( ',', "\t", ';' );
		$best       = ',';
		$best_score = -1;
		$aliases_o  = array( '原文', 'original', 'source' );
		$aliases_t  = array( '翻譯', 'translated', 'translation', '譯文' );

		foreach ( $candidates as $delimiter ) {
			$cols = str_getcsv( $first, $delimiter, '"', '' );
			$norm = array_map( function ( $v ) {
				$v = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $v );
				return mb_strtolower( trim( $v ) );
			}, $cols );

			$score = count( $cols );
			if ( array_intersect( $aliases_o, $norm ) ) {
				$score += 10;
			}
			if ( array_intersect( $aliases_t, $norm ) ) {
				$score += 10;
			}
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $delimiter;
			}
		}

		return $best;
	}

	protected static function normalize_language_code( string $lang ): string {
		return strtolower( str_replace( '-', '_', trim( $lang ) ) );
	}

	protected static function looks_like_language_code( string $value ): bool {
		$value = trim( $value );
		return (bool) preg_match( '/^[A-Za-z]{2,3}(?:[-_][A-Za-z]{2,4})?$/', $value );
	}

	/**
	 * AI 有時會「看起來像 CSV」，但沒有把含逗號的英文譯文用雙引號包住。
	 * 例如：
	 * 原文,We build websites, brands and stores,en_US,14
	 * 會被標準 CSV 解析器拆成 6 欄。
	 *
	 * 這裡會利用：
	 * 1. 使用者目前選擇的目標語言（例如 en_US）
	 * 2. 外掛已探索過的原文字串
	 * 自動把被逗號拆開的「原文／翻譯」重新合併回正確的四欄。
	 */
	protected static function repair_ai_csv_row( array $row, string $delimiter, string $expected_target_lang, array $known_originals ): array {
		$result = array(
			'ok'         => false,
			'original'   => '',
			'translated' => '',
			'target'     => '',
			'repaired'   => false,
		);

		if ( empty( $row ) ) {
			return $result;
		}

		$expected_norm = self::normalize_language_code( $expected_target_lang );
		$target_pos    = null;

		// 優先從右往左找「目前選擇的目標語言」，避免英文譯文中的一般單字被誤判成語言代碼。
		if ( '' !== $expected_norm ) {
			for ( $i = count( $row ) - 1; $i >= 0; $i-- ) {
				if ( self::normalize_language_code( (string) $row[ $i ] ) === $expected_norm ) {
					$target_pos = $i;
					break;
				}
			}
		}

		// 若沒有指定目標語言，再退回找像 en / en_US / zh-TW 的欄位。
		if ( null === $target_pos ) {
			for ( $i = count( $row ) - 1; $i >= 0; $i-- ) {
				if ( self::looks_like_language_code( (string) $row[ $i ] ) ) {
					$target_pos = $i;
					break;
				}
			}
		}

		if ( null === $target_pos || $target_pos < 1 ) {
			return $result;
		}

		$target = trim( (string) $row[ $target_pos ] );

		// 找出原文結束位置。優先使用已探索原文精準比對，這樣原文本身含半形逗號也能修復。
		$original_end = null;
		for ( $end = $target_pos - 1; $end >= 0; $end-- ) {
			$candidate = implode( $delimiter, array_slice( $row, 0, $end + 1 ) );
			if ( isset( $known_originals[ $candidate ] ) ) {
				$original_end = $end;
				break;
			}
		}

		// 找不到已探索原文時，依標準四欄格式把第一欄視為原文，其餘直到目標語言前都視為翻譯。
		if ( null === $original_end ) {
			$original_end = 0;
		}

		$translation_start = $original_end + 1;
		if ( $translation_start >= $target_pos ) {
			return $result;
		}

		$original   = implode( $delimiter, array_slice( $row, 0, $original_end + 1 ) );
		$translated = implode( $delimiter, array_slice( $row, $translation_start, $target_pos - $translation_start ) );

		$result['ok']         = '' !== trim( $original );
		$result['original']   = $original;
		$result['translated'] = trim( $translated );
		$result['target']     = $target;
		$result['repaired']   = count( $row ) > 4 || $original_end > 0;

		return $result;
	}

	public static function parse_import_csv( string $csv_content, string $expected_target_lang = '' ): array {
		if ( '' === $csv_content ) {
			return array( 'ok' => false, 'error' => 'CSV 檔案內容是空的。' );
		}

		$normalized  = self::convert_csv_to_utf8( $csv_content );
		$csv_content = $normalized['content'];
		$encoding    = $normalized['encoding'];
		$delimiter   = self::detect_csv_delimiter( $csv_content );

		// Excel 有時會在第一列加入 sep=,，先移除。
		$csv_content = preg_replace( '/^\s*sep\s*=\s*[,;\t]\s*\n/i', '', $csv_content, 1 );

		$fh = fopen( 'php://temp', 'w+' );
		if ( ! $fh ) {
			return array( 'ok' => false, 'error' => '暫存 CSV 失敗。' );
		}
		fwrite( $fh, $csv_content );
		rewind( $fh );

		$header = fgetcsv( $fh, 0, $delimiter, '"', '' );
		if ( ! $header ) {
			fclose( $fh );
			return array( 'ok' => false, 'error' => '無法讀取 CSV 標題列，請確認檔案格式正確。' );
		}

		$header = array_map( function ( $h ) {
			$h = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h );
			return mb_strtolower( trim( $h ) );
		}, $header );

		$original_idx   = null;
		$translated_idx = null;
		$target_idx     = null;
		$source_id_idx  = null;

		foreach ( $header as $idx => $col ) {
			if ( in_array( $col, array( '原文', 'original', 'source' ), true ) ) {
				$original_idx = $idx;
			}
			if ( in_array( $col, array( '翻譯', 'translated', 'translation', '譯文' ), true ) ) {
				$translated_idx = $idx;
			}
			if ( in_array( $col, array( '目標語言', 'target language', 'target_lang', 'target language code' ), true ) ) {
				$target_idx = $idx;
			}
			if ( in_array( $col, array( '來源文章id', '來源文章 id', 'source post id', 'post id', 'post_id' ), true ) ) {
				$source_id_idx = $idx;
			}
		}

		if ( null === $original_idx || null === $translated_idx ) {
			fclose( $fh );
			$shown_delimiter = "\t" === $delimiter ? 'TAB' : $delimiter;
			return array(
				'ok'    => false,
				'error' => '找不到「原文」與「翻譯」欄。偵測到編碼：' . $encoding . '，分隔符：' . $shown_delimiter . '。請使用本外掛匯出的 CSV 範本，不要改欄位名稱。',
			);
		}

		$known_originals = array_fill_keys( WU_AIT_Discovery_Service::get_all_discovered(), true );
		$header_count    = count( $header );
		$expected_norm   = self::normalize_language_code( $expected_target_lang );

		$map                    = array();
		$skipped                = 0;
		$total_rows             = 0;
		$duplicate_rows         = 0;
		$repaired_rows          = 0;
		$malformed_rows         = 0;
		$target_languages       = array();
		$wrong_target_languages = array();
		$blank_originals        = array();

		while ( ( $row = fgetcsv( $fh, 0, $delimiter, '"', '' ) ) !== false ) {
			if ( 1 === count( $row ) && '' === trim( (string) $row[0] ) ) {
				continue;
			}

			$total_rows++;
			$original   = '';
			$translated = '';
			$target     = '';

			// 正常 CSV：欄數與標題一致，直接依欄位位置讀取。
			if ( count( $row ) === $header_count && array_key_exists( $original_idx, $row ) ) {
				$original   = (string) $row[ $original_idx ];
				$translated = array_key_exists( $translated_idx, $row ) ? trim( (string) $row[ $translated_idx ] ) : '';
				$target     = ( null !== $target_idx && isset( $row[ $target_idx ] ) ) ? trim( (string) $row[ $target_idx ] ) : '';
			} else {
				// AI 產出的不標準 CSV 常見錯誤：英文翻譯含逗號卻沒有雙引號，造成一列被拆成 5、6、7... 欄。
				$fixed = self::repair_ai_csv_row( $row, $delimiter, $expected_target_lang, $known_originals );
				if ( ! $fixed['ok'] ) {
					$malformed_rows++;
					continue;
				}
				$original   = $fixed['original'];
				$translated = $fixed['translated'];
				$target     = $fixed['target'];
				if ( $fixed['repaired'] ) {
					$repaired_rows++;
				}
			}

			$original = (string) $original;
			if ( '' === trim( $original ) ) {
				continue;
			}

			// 目標語言只接受真正像語言代碼的值；不再把 brands / SSL / 句子片段誤當成語言。
			if ( '' !== $target && self::looks_like_language_code( $target ) ) {
				$target_languages[] = $target;
				if ( '' !== $expected_norm && self::normalize_language_code( $target ) !== $expected_norm ) {
					$wrong_target_languages[] = $target;
				}
			}

			if ( '' === $translated ) {
				$skipped++;
				if ( count( $blank_originals ) < 20 ) {
					$blank_originals[] = self::normalize_csv_preview( $original );
				}
				continue;
			}

			if ( array_key_exists( $original, $map ) ) {
				$duplicate_rows++;
			}
			$map[ $original ] = $translated;
		}
		fclose( $fh );

		$target_languages       = array_values( array_unique( array_filter( $target_languages ) ) );
		$wrong_target_languages = array_values( array_unique( array_filter( $wrong_target_languages ) ) );
		$shown_delimiter        = "\t" === $delimiter ? 'TAB' : $delimiter;

		return array(
			'ok'                     => true,
			'map'                    => $map,
			'total_rows'             => $total_rows,
			'matched'                => count( $map ),
			'skipped'                => $skipped,
			'duplicate_rows'         => $duplicate_rows,
			'repaired_rows'          => $repaired_rows,
			'malformed_rows'         => $malformed_rows,
			'encoding'               => $encoding,
			'delimiter'              => $shown_delimiter,
			'target_languages'       => $target_languages,
			'wrong_target_languages' => $wrong_target_languages,
			'blank_originals'        => $blank_originals,
		);
	}

	
protected static function normalize_csv_preview( string $text ): string {
    $text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
    return mb_strlen( $text ) > 120 ? mb_substr( $text, 0, 117 ) . '...' : $text;
}

public static function build_ai_prompt( string $target_lang, string $source_lang = 'zh-TW' ): string {
    $safe_lang = preg_replace( '/[^A-Za-z0-9_-]/', '-', $target_lang );
    $filename  = 'wu-ai-translate-import-' . $safe_lang . '.csv';

    $lines = array();
    $lines[] = '你是專業的網站本地化翻譯員。我會上傳一份 CSV。請直接讀取附件、完成翻譯，最後建立新的 CSV 檔案附件給我。不要要求我把 CSV 內容貼到聊天室。';
    $lines[] = '';
    $lines[] = '【最重要：所有空白翻譯都必須補完】';
    $lines[] = "1. CSV 固定四欄：原文、翻譯、目標語言、來源文章ID。凡是「翻譯」欄為空白的資料列，都必須把「原文」從 {$source_lang} 翻譯成 {$target_lang} 並填入翻譯欄。完成檔案不得留下任何空白翻譯儲存格。";
    $lines[] = '2. 如果原文是品牌、型號、數字、Email、網址或本來就不需翻譯，請把原文原樣複製到「翻譯」欄；仍然不得留白。';
    $lines[] = '3. 原本「翻譯」欄已有內容的列保持不變，不要重新翻譯。';
    $lines[] = '4. 「原文」「目標語言」「來源文章ID」禁止修改；禁止重新排序、刪除、合併、增加資料列。輸出資料列數必須與輸入完全一致。';
    $lines[] = '5. 混合文字也要翻譯，例如「10GB 備援空間」要保留 10GB，只翻譯文字部分；「NT$1,800 / 年」保留金額與符號，只翻譯需要翻譯的文字。';
    $lines[] = '6. 完整保留 HTML 標籤、HTML 屬性、網址、Email、品牌名、短碼、變數、佔位符，例如 <strong>、<a href="...">、%s、%1$s、{name}、[shortcode]。如果原文含 HTML，譯文必須保留同樣 HTML 結構，只翻譯可見文字。';
    $lines[] = '7. 不要在譯文加入 [JA]、[EN]、Translation:、引號、註解或任何原文不存在的說明。翻譯要自然、適合正式網站。';
    $lines[] = '';
    $lines[] = '【CSV 格式規則】';
    $lines[] = '8. 必須使用真正的 CSV writer 建立檔案，不要手動用逗號拼字串。若可執行 Python，使用 csv.writer，encoding="utf-8-sig"，newline=""，lineterminator="\\r\\n"，quoting=csv.QUOTE_ALL。';
    $lines[] = '9. 每一列必須剛好 4 欄。譯文內的半形逗號、雙引號、換行都必須由 CSV writer 正確 quoting / escaping，不得拆成第 5 欄以上。';
    $lines[] = "10. 目標語言欄每列必須原封不動保持為 {$target_lang}。檔案必須 UTF-8 with BOM，分隔符半形逗號，換行 CRLF。";
    $lines[] = '';
    $lines[] = '【交付前強制驗證】';
    $lines[] = '11. 產生檔案後，請用 CSV reader 重新讀回並檢查：每列恰好 4 欄、資料列數與原檔完全相同、原文欄完全未改、來源文章ID完全未改、目標語言欄完全未改。';
    $lines[] = '12. 再檢查「翻譯」欄：除標題列外，空白儲存格數必須是 0。若不是 0，請繼續完成翻譯並重新輸出，不能把有空白翻譯的檔案交給我。';
    $lines[] = '';
    $lines[] = "13. 最後只回傳可下載的 {$filename} CSV 檔案附件；不要把整份 CSV 貼在聊天訊息、不要 Markdown code block、不要 XLSX、不要額外解說。";
    $lines[] = '';
    $lines[] = '請現在直接處理我上傳的 CSV。完成後先驗證「每列 4 欄＋翻譯空白數為 0」，再回傳 CSV 檔案。';

    return implode( "\n", $lines );
}
}  add_action( WU_AIT_CRON_HOOK, function () { 	$settings = wu_ait_get_settings(); 	if ( empty( $settings['auto_cron'] ) ) { 		return; 	}  	$languages = WU_AIT_TranslatePress_Bridge::get_target_languages(); 	foreach ( $languages as $lang ) { 		$res = WU_AIT_TranslatePress_Bridge::run_batch_sitewide( $lang, (int) $settings['batch_size'] * 3 ); 		if ( $res['ok'] ) { 			wu_ait_add_log( sprintf( '[Cron][全站] %s：翻譯 %d 筆。', $lang, $res['translated_count'] ) ); 		} else { 			wu_ait_add_log( sprintf( '[Cron][全站] %s：失敗 - %s', $lang, $res['error'] ), 'error' ); 		} 	} } );  /**  * ------------------------------------------------------------------  *  後台選單與設定頁  * ------------------------------------------------------------------  */ add_action( 'admin_menu', function () { 	add_options_page( 'Wu AI 翻譯設定', 'Wu AI 翻譯', 'manage_options', 'wu-ai-translate', 'wu_ait_render_settings_page' ); } );  add_action( 'admin_post_wu_ait_save_settings', function () { 	if ( ! current_user_can( 'manage_options' ) ) { 		wp_die( '權限不足' ); 	} 	check_admin_referer( 'wu_ait_save_settings' );  	$new_settings = array( 		'provider'          => sanitize_text_field( $_POST['provider'] ?? 'gemini' ), 		'model_gemini'      => wu_ait_sanitize_model_code( sanitize_text_field( wp_unslash( $_POST['model_gemini'] ?? '' ) ) ), 		'model_claude'      => wu_ait_sanitize_model_code( sanitize_text_field( wp_unslash( $_POST['model_claude'] ?? '' ) ) ), 		'model_perplexity'  => wu_ait_sanitize_model_code( sanitize_text_field( wp_unslash( $_POST['model_perplexity'] ?? '' ) ) ), 		'model_openai'      => wu_ait_sanitize_model_code( sanitize_text_field( wp_unslash( $_POST['model_openai'] ?? '' ) ) ), 		'temperature'       => (float) ( $_POST['temperature'] ?? 0.2 ), 		'batch_size'        => (int) ( $_POST['batch_size'] ?? 10 ), 		'auto_cron'         => isset( $_POST['auto_cron'] ) ? 1 : 0, 		'cache_enabled'     => isset( $_POST['cache_enabled'] ) ? 1 : 0, 		'custom_glossary'   => sanitize_textarea_field( $_POST['custom_glossary'] ?? '' ), 		'extra_prompt'      => sanitize_textarea_field( $_POST['extra_prompt'] ?? '' ), 		'fallback_provider' => sanitize_text_field( $_POST['fallback_provider'] ?? '' ), 	);  	$current = wu_ait_get_settings(); 	foreach ( array( 'gemini', 'claude', 'perplexity', 'openai' ) as $key ) { 		$field  = 'api_key_' . $key; 		$posted = isset( $_POST[ $field ] ) ? trim( (string) $_POST[ $field ] ) : ''; 		$new_settings[ $field ] = ( '' !== $posted ) ? $posted : $current[ $field ]; 	}  	wu_ait_update_settings( $new_settings );  	wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'saved' => 1 ), admin_url( 'options-general.php' ) ) ); 	exit; } );  add_action( 'wp_ajax_wu_ait_verify_provider', function () { 	if ( ! current_user_can( 'manage_options' ) ) { 		wp_send_json_error( array( 'message' => '權限不足' ) ); 	} 	check_ajax_referer( 'wu_ait_verify_provider' );  	$provider_key = sanitize_text_field( $_POST['provider'] ?? '' ); 	$api_key      = trim( (string) ( $_POST['api_key'] ?? '' ) ); 	$model        = wu_ait_sanitize_model_code( (string) ( $_POST['model'] ?? '' ) );  	$current = wu_ait_get_settings(); 	if ( '' === $api_key ) { 		$api_key = $current[ 'api_key_' . $provider_key ] ?? ''; 	}  	$settings_override = $current; 	$settings_override[ 'api_key_' . $provider_key ] = $api_key; 	$settings_override[ 'model_' . $provider_key ]   = $model;  	$provider = WU_AIT_Provider_Factory::make( $settings_override, $provider_key );  	if ( ! $provider ) { 		wp_send_json_error( array( 'message' => '未知的供應商：' . $provider_key ) ); 	}  	$result = $provider->verify_connection();  	if ( $result['ok'] ) { 		wp_send_json_success( array( 'message' => $result['message'] ) ); 	} else { 		wp_send_json_error( array( 'message' => $result['message'] ) ); 	} } );  add_action( 'wp_ajax_wu_ait_scan_sitewide', function () { 	if ( ! current_user_can( 'manage_options' ) ) { 		wp_send_json_error( array( 'message' => '權限不足' ) ); 	} 	check_ajax_referer( 'wu_ait_scan_sitewide' );  	$result = WU_AIT_Discovery_Service::discover_sitewide();  	wu_ait_add_log( sprintf( '手動全站掃描完成：掃描 %d 篇公開內容，內容掃描 %d 筆，TranslatePress 頁面精準補抓 %d 筆，清理技術垃圾字串 %d 筆，目前累積已探索字串共 %d 筆。', $result['scanned_posts'], $result['this_run_segments'], $result['dictionary_segments'], $result['cleaned_invalid'] ?? 0, $result['total_discovered'] ) );  	wp_send_json_success( array( 		'message' => sprintf( '掃描完成！共掃描 %d 篇公開內容；可見內容 / 頁面字典精準掃描取得 %d 筆，TranslatePress 頁面精準補抓 %d 筆，清理舊版技術垃圾字串 %d 筆；目前累積已探索字串：%d 筆。', $result['scanned_posts'], $result['this_run_segments'], $result['dictionary_segments'], $result['cleaned_invalid'] ?? 0, $result['total_discovered'] ), 	) ); } );  add_action( 'wp_ajax_wu_ait_get_ai_prompt', function () { 	if ( ! current_user_can( 'manage_options' ) ) { 		wp_send_json_error( array( 'message' => '權限不足' ) ); 	} 	check_ajax_referer( 'wu_ait_get_ai_prompt' );  	$target_lang = sanitize_text_field( $_POST['target_lang'] ?? 'en' ); 	$source_lang = WU_AIT_TranslatePress_Bridge::get_default_language();  	$prompt = WU_AIT_Export_Import_Service::build_ai_prompt( $target_lang, $source_lang );  	wp_send_json_success( array( 'prompt' => $prompt ) ); } );  add_action( 'admin_post_wu_ait_test_translate', function () { 	if ( ! current_user_can( 'manage_options' ) ) { 		wp_die( '權限不足' ); 	} 	check_admin_referer( 'wu_ait_test_translate' );  	$sample_text = sanitize_text_field( $_POST['sample_text'] ?? '歡迎使用我們的網站' ); 	$target_lang = sanitize_text_field( $_POST['sample_target'] ?? 'en' );  	$result = WU_AIT_Translation_Service::translate_texts( array( $sample_text ), $target_lang, 'zh-TW' );  	set_transient( 'wu_ait_test_result', $result, 60 );  	wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'tested' => 1 ), admin_url( 'options-general.php' ) ) ); 	exit; } );  add_action( 'admin_post_wu_ait_flush_cache', function () { 	if ( ! current_user_can( 'manage_options' ) ) { 		wp_die( '權限不足' ); 	} 	check_admin_referer( 'wu_ait_flush_cache' );  	WU_AIT_Translation_Service::flush_cache();  	wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'flushed' => 1 ), admin_url( 'options-general.php' ) ) ); 	exit; } );  add_action( 'admin_post_wu_ait_clear_discovery', function () { 	if ( ! current_user_can( 'manage_options' ) ) { 		wp_die( '權限不足' ); 	} 	check_admin_referer( 'wu_ait_clear_discovery' );  	WU_AIT_Discovery_Service::clear_all();  	wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'discovery_cleared' => 1 ), admin_url( 'options-general.php' ) ) ); 	exit; } );  add_action( 'admin_post_wu_ait_run_batch_now', function () { 	if ( ! current_user_can( 'manage_options' ) ) { 		wp_die( '權限不足' ); 	} 	check_admin_referer( 'wu_ait_run_batch_now' );  	$lang    = sanitize_text_field( $_POST['batch_lang'] ?? '' ); 	$scope   = sanitize_text_field( $_POST['batch_scope'] ?? 'sitewide' ); 	$post_id = (int) ( $_POST['batch_post_id'] ?? 0 );  	if ( '' === $lang ) { 		wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'error' => 'no_lang' ), admin_url( 'options-general.php' ) ) ); 		exit; 	} 	if ( 'post' === $scope && $post_id <= 0 ) { 		wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'error' => 'no_post' ), admin_url( 'options-general.php' ) ) ); 		exit; 	}  	$settings = wu_ait_get_settings();  	if ( 'post' === $scope ) { 		$result = WU_AIT_TranslatePress_Bridge::run_batch_for_post( $post_id, $lang ); 	} else { 		$result = WU_AIT_TranslatePress_Bridge::run_batch_sitewide( $lang, (int) $settings['batch_size'] * 5 ); 	}  	set_transient( 'wu_ait_batch_result', $result, 60 );  	wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'batched' => 1 ), admin_url( 'options-general.php' ) ) ); 	exit; } );  add_action( 'admin_post_wu_ait_export_csv', function () { 	if ( ! current_user_can( 'manage_options' ) ) { 		wp_die( '權限不足' ); 	} 	check_admin_referer( 'wu_ait_export_csv' );  	$lang    = sanitize_text_field( $_POST['export_lang'] ?? '' ); 	$scope   = sanitize_text_field( $_POST['export_scope'] ?? 'sitewide' ); 	$post_id = (int) ( $_POST['export_post_id'] ?? 0 );  	if ( '' === $lang ) { 		wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'error' => 'no_lang' ), admin_url( 'options-general.php' ) ) ); 		exit; 	}  	$export = WU_AIT_Export_Import_Service::build_export_csv( $lang, $scope, $post_id );  	if ( ! $export['ok'] ) { 		set_transient( 'wu_ait_export_error', $export['error'], 60 ); 		wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'export_failed' => 1 ), admin_url( 'options-general.php' ) ) ); 		exit; 	}  	nocache_headers(); 	header( 'Content-Type: text/csv; charset=utf-8' ); 	header( 'Content-Disposition: attachment; filename="' . $export['filename'] . '"' ); 	header( 'Content-Length: ' . strlen( $export['csv'] ) ); 	echo $export['csv']; 	wu_ait_add_log( sprintf( '匯出 CSV：語言 %s，範圍 %s，共 %d 筆字串。', $lang, $scope, $export['count'] ) ); 	exit; } );  add_action( 'admin_post_wu_ait_import_csv', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '權限不足' );
	}
	check_admin_referer( 'wu_ait_import_csv' );

	$lang = sanitize_text_field( $_POST['import_lang'] ?? '' );
	if ( '' === $lang ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'error' => 'no_lang' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	if ( empty( $_FILES['import_file'] ) || UPLOAD_ERR_OK !== (int) $_FILES['import_file']['error'] ) {
		set_transient( 'wu_ait_import_result', array( 'ok' => false, 'error' => '請選擇要上傳的 CSV 檔案。' ), 60 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'imported' => 1 ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	$filename = sanitize_file_name( wp_unslash( $_FILES['import_file']['name'] ) );
	if ( ! preg_match( '/\.csv$/i', $filename ) ) {
		set_transient( 'wu_ait_import_result', array( 'ok' => false, 'error' => '請上傳 .csv 格式檔案，不要上傳 XLSX。' ), 60 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'imported' => 1 ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	$csv_content = file_get_contents( $_FILES['import_file']['tmp_name'] );
	if ( false === $csv_content ) {
		set_transient( 'wu_ait_import_result', array( 'ok' => false, 'error' => '讀取上傳 CSV 失敗。' ), 60 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'imported' => 1 ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	$parsed = WU_AIT_Export_Import_Service::parse_import_csv( $csv_content, $lang );
	if ( ! $parsed['ok'] ) {
		set_transient( 'wu_ait_import_result', $parsed, 60 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'imported' => 1 ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	// 只針對「真正有效的語言代碼」檢查錯誤，不再把翻譯中的 brands / SSL / 句子片段誤判成目標語言。
	if ( ! empty( $parsed['wrong_target_languages'] ) ) {
		set_transient( 'wu_ait_import_result', array(
			'ok'    => false,
			'error' => 'CSV 內確實包含與目前匯入語言不同的有效語言代碼：' . implode( ', ', $parsed['wrong_target_languages'] ) . '；目前選擇：' . $lang . '。請確認檔案是否選錯。',
		), 60 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'imported' => 1 ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	if ( empty( $parsed['map'] ) ) {
		set_transient( 'wu_ait_import_result', array(
			'ok'    => false,
			'error' => 'CSV 可讀取，但「翻譯」欄沒有任何可匯入內容。請確認 AI 已把譯文填在「翻譯」欄，而不是新增其他欄位。',
		), 60 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'imported' => 1 ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	$updated       = WU_AIT_TranslatePress_Bridge::upsert_translations_by_original( $lang, $parsed['map'] );
	$write_report  = WU_AIT_TranslatePress_Bridge::get_last_write_report();
	$errors        = ! empty( $write_report['errors'] ) ? array_values( array_unique( $write_report['errors'] ) ) : array();
	$flushed_cache = array();

	if ( $updated > 0 ) {
		$flushed_cache = wu_ait_flush_site_caches();
	}

	if ( 0 === $updated ) {
		$error = 'CSV 已成功解析，但沒有任何翻譯寫入 TranslatePress。';
		if ( ! empty( $write_report['table'] ) ) {
			$error .= ' 目標字典表：' . $write_report['table'] . '。';
		}
		if ( ! empty( $errors ) ) {
			$error .= ' 原因：' . implode( '；', array_slice( $errors, 0, 3 ) );
		}

		set_transient( 'wu_ait_import_result', array(
			'ok'                 => false,
			'error'              => $error,
			'total_rows'         => $parsed['total_rows'],
			'matched'            => $parsed['matched'],
			'updated'            => 0,
			'skipped'            => $parsed['skipped'],
			'blank_originals'    => $parsed['blank_originals'] ?? array(),
			'encoding'           => $parsed['encoding'] ?? '',
			'delimiter'          => $parsed['delimiter'] ?? '',
			'repaired_rows'      => $parsed['repaired_rows'] ?? 0,
			'malformed_rows'     => $parsed['malformed_rows'] ?? 0,
			'table'              => $write_report['table'] ?? '',
		), 60 );
	} else {
		$partial = ! empty( $parsed['skipped'] ) || ! empty( $parsed['malformed_rows'] );

		wu_ait_add_log( sprintf(
			'匯入 CSV：語言 %s，CSV %d 列，可匯入 %d 筆，成功寫入 %d 個字串，實際更新 %d 個字典列；exact %d、normalized %d、多列同步 %d、空白 %d、資料表 %s。',
			$lang,
			$parsed['total_rows'],
			$parsed['matched'],
			$updated,
			$write_report['rows_updated'] ?? 0,
			$write_report['exact_matches'] ?? 0,
			$write_report['normalized_matches'] ?? 0,
			$write_report['multi_row_matches'] ?? 0,
			$parsed['skipped'],
			$write_report['table'] ?? '未知'
		) );

		set_transient( 'wu_ait_import_result', array(
			'ok'                 => true,
			'partial'            => $partial,
			'total_rows'         => $parsed['total_rows'],
			'matched'            => $parsed['matched'],
			'updated'            => $updated,
			'skipped'            => $parsed['skipped'],
			'blank_originals'    => $parsed['blank_originals'] ?? array(),
			'duplicate_rows'     => $parsed['duplicate_rows'] ?? 0,
			'encoding'           => $parsed['encoding'] ?? '',
			'delimiter'          => $parsed['delimiter'] ?? '',
			'repaired_rows'      => $parsed['repaired_rows'] ?? 0,
			'malformed_rows'     => $parsed['malformed_rows'] ?? 0,
			'table'              => $write_report['table'] ?? '',
			'rows_updated'       => $write_report['rows_updated'] ?? 0,
			'exact_matches'      => $write_report['exact_matches'] ?? 0,
			'normalized_matches' => $write_report['normalized_matches'] ?? 0,
			'multi_row_matches'  => $write_report['multi_row_matches'] ?? 0,
			'inserted'           => $write_report['inserted'] ?? 0,
			'cache_flushed'      => $flushed_cache,
		), 60 );
	}

	wp_safe_redirect( add_query_arg( array( 'page' => 'wu-ai-translate', 'imported' => 1 ), admin_url( 'options-general.php' ) ) );
	exit;
} );

function wu_ait_render_settings_page() { 	if ( ! current_user_can( 'manage_options' ) ) { 		return; 	}  	$settings              = wu_ait_get_settings(); 	$provider_labels        = WU_AIT_Provider_Factory::get_provider_labels(); 	$model_reference_links  = WU_AIT_Provider_Factory::get_model_reference_links(); 	$model_placeholders     = WU_AIT_Provider_Factory::get_model_placeholders(); 	$languages              = WU_AIT_TranslatePress_Bridge::get_target_languages(); 	$translatable_posts     = WU_AIT_TranslatePress_Bridge::get_translatable_posts(); 	$logs                   = get_option( WU_AIT_LOG_OPTION_KEY, array() ); 	$discovered_count       = WU_AIT_Discovery_Service::count_discovered(); 	$default_lang           = WU_AIT_TranslatePress_Bridge::get_default_language();  	$test_result   = get_transient( 'wu_ait_test_result' ); 	$batch_result  = get_transient( 'wu_ait_batch_result' ); 	$import_result = get_transient( 'wu_ait_import_result' ); 	$export_error  = get_transient( 'wu_ait_export_error' ); 	?> 	<div class="wrap"> 		<h1>Wu AI 翻譯設定</h1>  		<?php if ( isset( $_GET['saved'] ) ) : ?> 			<div class="notice notice-success is-dismissible"><p>設定已儲存。</p></div> 		<?php endif; ?> 		<?php if ( isset( $_GET['flushed'] ) ) : ?> 			<div class="notice notice-success is-dismissible"><p>快取已清除。</p></div> 		<?php endif; ?> 		<?php if ( isset( $_GET['discovery_cleared'] ) ) : ?> 			<div class="notice notice-success is-dismissible"><p>已探索清單已清除。</p></div> 		<?php endif; ?> 		<?php if ( isset( $_GET['error'] ) && 'no_lang' === $_GET['error'] ) : ?> 			<div class="notice notice-error is-dismissible"><p>請先選擇目標語言。</p></div> 		<?php endif; ?> 		<?php if ( isset( $_GET['error'] ) && 'no_post' === $_GET['error'] ) : ?> 			<div class="notice notice-error is-dismissible"><p>請選擇要翻譯的頁面／文章。</p></div> 		<?php endif; ?> 		<?php if ( isset( $_GET['export_failed'] ) && $export_error ) : ?> 			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $export_error ); ?></p></div> 			<?php delete_transient( 'wu_ait_export_error' ); ?> 		<?php endif; ?>  		<h2 class="nav-tab-wrapper"> 			<a href="#settings" class="nav-tab nav-tab-active" onclick="wuAitTab(event,'settings')">供應商與 API 金鑰</a> 			<a href="#batch" class="nav-tab" onclick="wuAitTab(event,'batch')">批次翻譯</a> 			<a href="#exportimport" class="nav-tab" onclick="wuAitTab(event,'exportimport')">匯出／匯入</a> 			<a href="#progress" class="nav-tab" onclick="wuAitTab(event,'progress')">翻譯進度</a> 			<a href="#test" class="nav-tab" onclick="wuAitTab(event,'test')">測試翻譯</a> 			<a href="#logs" class="nav-tab" onclick="wuAitTab(event,'logs')">日誌</a> 		</h2>  		<div id="tab-settings" class="wu-ait-tab"> 			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"> 				<?php wp_nonce_field( 'wu_ait_save_settings' ); ?> 				<input type="hidden" name="action" value="wu_ait_save_settings" />  				<table class="form-table"> 					<tr> 						<th><label for="provider">使用的供應商</label></th> 						<td> 							<select name="provider" id="provider" onchange="wuAitToggleProviderFields()"> 								<?php foreach ( $provider_labels as $key => $label ) : ?> 									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['provider'], $key ); ?>><?php echo esc_html( $label ); ?></option> 								<?php endforeach; ?> 							</select> 						</td> 					</tr> 				</table>  				<?php foreach ( $provider_labels as $key => $label ) : ?> 					<div class="wu-ait-provider-fields" data-provider="<?php echo esc_attr( $key ); ?>" style="display:none;"> 						<h3><?php echo esc_html( $label ); ?> 設定</h3> 						<table class="form-table"> 							<tr> 								<th><label for="api_key_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?> API 金鑰</label></th> 								<td> 									<input type="password" class="regular-text" name="api_key_<?php echo esc_attr( $key ); ?>" id="api_key_<?php echo esc_attr( $key ); ?>" placeholder="<?php echo ! empty( $settings[ 'api_key_' . $key ] ) ? '••••••••（已設定，留空表示保留）' : 'sk-...'; ?>" autocomplete="off" /> 								</td> 							</tr> 							<tr> 								<th><label for="model_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?> 模型代碼</label></th> 								<td> 									<input type="text" class="regular-text wu-ait-model-input" name="model_<?php echo esc_attr( $key ); ?>" id="model_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $settings[ 'model_' . $key ] ); ?>" placeholder="<?php echo esc_attr( $model_placeholders[ $key ] ); ?>" onblur="wuAitCleanModelInput(this)" /> 									<a href="<?php echo esc_url( $model_reference_links[ $key ] ); ?>" target="_blank" rel="noopener noreferrer">查詢官方最新模型清單 ↗</a> 									<button type="button" class="button" onclick="wuAitVerifyProvider('<?php echo esc_js( $key ); ?>')">驗證金鑰與模型</button> 									<span id="wu-ait-verify-result-<?php echo esc_attr( $key ); ?>" class="wu-ait-verify-result"></span> 								</td> 							</tr> 						</table> 					</div> 				<?php endforeach; ?>  				<table class="form-table"> 					<tr> 						<th><label for="temperature">Temperature（創意程度）</label></th> 						<td><input type="number" step="0.1" min="0" max="1" name="temperature" id="temperature" value="<?php echo esc_attr( $settings['temperature'] ); ?>" /></td> 					</tr> 					<tr> 						<th><label for="batch_size">每批次字數（句數）</label></th> 						<td><input type="number" min="1" max="50" name="batch_size" id="batch_size" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" /></td> 					</tr> 					<tr> 						<th><label for="cache_enabled">啟用翻譯快取</label></th> 						<td><label><input type="checkbox" name="cache_enabled" id="cache_enabled" <?php checked( $settings['cache_enabled'], 1 ); ?> /> 相同原文 24 小時內不重複呼叫 API</label></td> 					</tr> 					<tr> 						<th><label for="auto_cron">自動排程翻譯（全站）</label></th> 						<td><label><input type="checkbox" name="auto_cron" id="auto_cron" <?php checked( $settings['auto_cron'], 1 ); ?> /> 每 5 分鐘自動翻譯已探索清單中尚未翻譯的字串（探索本身仍需手動按「立即掃描全站」）</label></td> 					</tr> 					<tr> 						<th><label for="fallback_provider">備援供應商（選填）</label></th> 						<td> 							<select name="fallback_provider" id="fallback_provider"> 								<option value="">— 不啟用備援 —</option> 								<?php foreach ( $provider_labels as $key => $label ) : ?> 									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['fallback_provider'], $key ); ?>><?php echo esc_html( $label ); ?></option> 								<?php endforeach; ?> 							</select> 						</td> 					</tr> 					<tr> 						<th><label for="custom_glossary">自訂詞彙表</label></th> 						<td><textarea name="custom_glossary" id="custom_glossary" rows="5" class="large-text" placeholder="範例：&#10;高雄=Kaohsiung&#10;結帳=Checkout"><?php echo esc_textarea( $settings['custom_glossary'] ); ?></textarea></td> 					</tr> 					<tr> 						<th><label for="extra_prompt">額外風格指示</label></th> 						<td><textarea name="extra_prompt" id="extra_prompt" rows="3" class="large-text"><?php echo esc_textarea( $settings['extra_prompt'] ); ?></textarea></td> 					</tr> 				</table>  				<?php submit_button( '儲存設定' ); ?> 			</form>  			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('確定要清除所有 AI 翻譯快取嗎？');" style="display:inline-block; margin-right:10px;"> 				<?php wp_nonce_field( 'wu_ait_flush_cache' ); ?> 				<input type="hidden" name="action" value="wu_ait_flush_cache" /> 				<?php submit_button( '清除翻譯快取', 'secondary', 'submit', false ); ?> 			</form>  			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('確定要清除所有已探索字串紀錄嗎？');" style="display:inline-block;"> 				<?php wp_nonce_field( 'wu_ait_clear_discovery' ); ?> 				<input type="hidden" name="action" value="wu_ait_clear_discovery" /> 				<?php submit_button( '清除已探索清單', 'secondary', 'submit', false ); ?> 			</form> 		</div>  		<div id="tab-batch" class="wu-ait-tab" style="display:none;"> 			<h2>步驟一：掃描全站，找出可翻譯字串</h2> 			<p>目前已探索字串總數：<strong id="wu-ait-discovered-count"><?php echo esc_html( $discovered_count ); ?></strong></p> 			<button type="button" class="button button-secondary" id="wu-ait-scan-btn">立即掃描全站（不需前台瀏覽、不需進入文章編輯頁）</button> 			<span id="wu-ait-scan-result" style="margin-left:10px; font-size:13px;"></span> 			<p class="description">會自動撈出所有已發佈的文章與頁面，解析標題、內文、摘要文字並記錄，可重複執行以掃描新增或修改過的內容。</p>  			<hr />  			<h2>步驟二：執行 AI 翻譯</h2> 			<?php if ( empty( $languages ) ) : ?> 				<p>偵測不到 TranslatePress 已啟用的次要語言，請先確認 TranslatePress 已安裝並設定發佈語言。</p> 			<?php else : ?> 				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"> 					<?php wp_nonce_field( 'wu_ait_run_batch_now' ); ?> 					<input type="hidden" name="action" value="wu_ait_run_batch_now" /> 					<table class="form-table"> 						<tr> 							<th><label for="batch_lang">目標語言</label></th> 							<td> 								<select name="batch_lang" id="batch_lang"> 									<?php foreach ( $languages as $lang ) : ?> 										<option value="<?php echo esc_attr( $lang ); ?>"><?php echo esc_html( $lang ); ?></option> 									<?php endforeach; ?> 								</select> 							</td> 						</tr> 						<tr> 							<th>翻譯範圍</th> 							<td> 								<label style="margin-right:20px;"><input type="radio" name="batch_scope" value="sitewide" checked onclick="wuAitToggleScope('batch')" /> 全站（翻譯已探索清單中尚未翻譯的字串）</label> 								<label><input type="radio" name="batch_scope" value="post" onclick="wuAitToggleScope('batch')" /> 單一頁面／文章</label> 							</td> 						</tr> 						<tr id="wu-ait-batch-post-row" style="display:none;"> 							<th><label for="batch_post_id">選擇頁面／文章</label></th> 							<td> 								<select name="batch_post_id" id="batch_post_id"> 									<option value="">— 請選擇 —</option> 									<?php foreach ( $translatable_posts as $item ) : ?> 										<option value="<?php echo esc_attr( $item['id'] ); ?>">[<?php echo esc_html( $item['type'] ); ?>] <?php echo esc_html( $item['title'] ); ?></option> 									<?php endforeach; ?> 								</select> 							</td> 						</tr> 					</table> 					<?php submit_button( '立即執行翻譯（呼叫 AI）' ); ?> 				</form>  				<?php if ( $batch_result ) : ?> 					<div class="notice notice-<?php echo $batch_result['ok'] ? 'success' : 'error'; ?>"> 						<p><?php echo esc_html( wp_json_encode( $batch_result, JSON_UNESCAPED_UNICODE ) ); ?></p> 					</div> 					<?php delete_transient( 'wu_ait_batch_result' ); ?> 				<?php endif; ?> 			<?php endif; ?> 		</div>  		<div id="tab-exportimport" class="wu-ait-tab" style="display:none;"> 			<h2>匯出字串</h2> 			<p>操作方式：先下載 CSV → 把 CSV 檔案上傳給 GPT／Gemini／Claude → 複製下方指令給 AI → 下載 AI 回傳的 CSV → 再匯入本站。</p> 			<p class="description">💡 匯出的檔案已使用 UTF-8 BOM。建議不要用 Excel 重新「另存成」其他編碼；若有開啟或修改需求，請確認存回 CSV UTF-8（逗號分隔）。匯入端也會自動嘗試辨識 UTF-8、UTF-16、Big5／CP950。</p>  			<?php if ( empty( $languages ) ) : ?> 				<p>偵測不到 TranslatePress 已啟用的次要語言。</p> 			<?php else : ?> 				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"> 					<?php wp_nonce_field( 'wu_ait_export_csv' ); ?> 					<input type="hidden" name="action" value="wu_ait_export_csv" /> 					<table class="form-table"> 						<tr> 							<th><label for="export_lang">目標語言</label></th> 							<td> 								<select name="export_lang" id="export_lang" onchange="wuAitRefreshPrompt()"> 									<?php foreach ( $languages as $lang ) : ?> 										<option value="<?php echo esc_attr( $lang ); ?>"><?php echo esc_html( $lang ); ?></option> 									<?php endforeach; ?> 								</select> 							</td> 						</tr> 						<tr> 							<th>匯出範圍</th> 							<td> 								<label style="margin-right:20px;"><input type="radio" name="export_scope" value="sitewide" checked onclick="wuAitToggleScope('export')" /> 全站已探索字串</label> 								<label><input type="radio" name="export_scope" value="post" onclick="wuAitToggleScope('export')" /> 單一頁面／文章</label> 							</td> 						</tr> 						<tr id="wu-ait-export-post-row" style="display:none;"> 							<th><label for="export_post_id">選擇頁面／文章</label></th> 							<td> 								<select name="export_post_id" id="export_post_id"> 									<option value="">— 請選擇 —</option> 									<?php foreach ( $translatable_posts as $item ) : ?> 										<option value="<?php echo esc_attr( $item['id'] ); ?>">[<?php echo esc_html( $item['type'] ); ?>] <?php echo esc_html( $item['title'] ); ?></option> 									<?php endforeach; ?> 								</select> 							</td> 						</tr> 					</table> 					<?php submit_button( '下載 CSV' ); ?> 				</form> 				<p class="description">CSV 內含「原文、翻譯、目標語言、來源文章ID」四欄；若該字串已有翻譯，「翻譯」欄會直接帶出目前內容。匯出前請先到「批次翻譯」分頁執行「立即掃描全站」。</p>  				<div style="background:#f0f6fc; border:1px solid #c3d4e0; border-radius:4px; padding:16px; margin-top:16px;"> 					<h3 style="margin-top:0;">AI 翻譯指令（一鍵複製）</h3> 					<p>先把剛下載的 CSV 檔案「直接上傳」到 GPT／Gemini／Claude，再貼上這段指令。指令會要求 AI 不要在對話貼一大串 CSV，而是直接回傳一個可下載的 UTF-8 BOM CSV 檔案。</p> 					<textarea id="wu-ait-ai-prompt" rows="10" class="large-text" readonly style="font-family:monospace; font-size:12px;"><?php echo esc_textarea( WU_AIT_Export_Import_Service::build_ai_prompt( ! empty( $languages ) ? $languages[0] : 'en', $default_lang ) ); ?></textarea> 					<p> 						<button type="button" class="button button-primary" id="wu-ait-copy-prompt-btn" onclick="wuAitCopyPrompt()">複製指令</button> 						<span id="wu-ait-copy-result" style="margin-left:8px; font-size:13px;"></span> 					</p> 				</div> 			<?php endif; ?>  			<hr />  			<h2>匯入翻譯</h2> 			<p>把 AI 回傳的 CSV 上傳回來。系統會自動檢查編碼、分隔符、目標語言與 TranslatePress 字典表；只有「翻譯」欄有內容的列才會寫入，空白列不會清空既有翻譯。</p>  			<?php if ( ! empty( $languages ) ) : ?> 				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data"> 					<?php wp_nonce_field( 'wu_ait_import_csv' ); ?> 					<input type="hidden" name="action" value="wu_ait_import_csv" /> 					<table class="form-table"> 						<tr> 							<th><label for="import_lang">目標語言</label></th> 							<td> 								<select name="import_lang" id="import_lang"> 									<?php foreach ( $languages as $lang ) : ?> 										<option value="<?php echo esc_attr( $lang ); ?>"><?php echo esc_html( $lang ); ?></option> 									<?php endforeach; ?> 								</select> 							</td> 						</tr> 						<tr> 							<th><label for="import_file">選擇 CSV 檔案</label></th> 							<td><input type="file" name="import_file" id="import_file" accept=".csv" required /></td> 						</tr> 					</table> 					<?php submit_button( '上傳並匯入' ); ?> 				</form> 			<?php endif; ?>  			<?php if ( $import_result ) : ?>
				<?php $notice_type = ! empty( $import_result['ok'] ) ? ( ! empty( $import_result['partial'] ) ? 'warning' : 'success' ) : 'error'; ?>
				<div class="notice notice-<?php echo esc_attr( $notice_type ); ?>">
					<p>
						<?php if ( ! empty( $import_result['ok'] ) ) : ?>
							<strong><?php echo ! empty( $import_result['partial'] ) ? '部分匯入完成' : '匯入完成'; ?>：</strong>
							CSV 共 <?php echo esc_html( $import_result['total_rows'] ); ?> 列，可匯入 <?php echo esc_html( $import_result['matched'] ); ?> 筆，成功寫入 <?php echo esc_html( $import_result['updated'] ); ?> 個字串。
							<?php if ( ! empty( $import_result['rows_updated'] ) ) : ?> 實際同步 <?php echo esc_html( $import_result['rows_updated'] ); ?> 個 TranslatePress 字典列。<?php endif; ?>
							<?php if ( ! empty( $import_result['multi_row_matches'] ) ) : ?> 其中 <?php echo esc_html( $import_result['multi_row_matches'] ); ?> 個原文同時更新了 regular / translation block 重複列。<?php endif; ?>
							<?php if ( ! empty( $import_result['normalized_matches'] ) ) : ?> 另有 <?php echo esc_html( $import_result['normalized_matches'] ); ?> 筆以空白/換行正規化比對成功。<?php endif; ?>
							<?php if ( ! empty( $import_result['inserted'] ) ) : ?> 新增 <?php echo esc_html( $import_result['inserted'] ); ?> 筆 regular string。<?php endif; ?>
							<?php if ( ! empty( $import_result['encoding'] ) ) : ?> 編碼：<?php echo esc_html( $import_result['encoding'] ); ?>。<?php endif; ?>
							<?php if ( ! empty( $import_result['delimiter'] ) ) : ?> 分隔符：<?php echo esc_html( $import_result['delimiter'] ); ?>。<?php endif; ?>
							<?php if ( ! empty( $import_result['repaired_rows'] ) ) : ?> 已自動修復 <?php echo esc_html( $import_result['repaired_rows'] ); ?> 列 AI CSV 拆欄。<?php endif; ?>
							<?php if ( ! empty( $import_result['table'] ) ) : ?> 寫入資料表：<code><?php echo esc_html( $import_result['table'] ); ?></code>。<?php endif; ?>
							<?php if ( ! empty( $import_result['cache_flushed'] ) ) : ?> 已清除：<?php echo esc_html( implode( '、', $import_result['cache_flushed'] ) ); ?>。<?php endif; ?>
						<?php else : ?>
							<strong>匯入失敗：</strong><?php echo esc_html( $import_result['error'] ); ?>
						<?php endif; ?>
					</p>

					<?php if ( ! empty( $import_result['skipped'] ) ) : ?>
						<p><strong>注意：</strong>這份 AI CSV 還有 <?php echo esc_html( $import_result['skipped'] ); ?> 列「翻譯」是空白，因此那些字串不可能出現在前台翻譯中。新版 AI 指令已改成「翻譯空白數必須為 0」；請把原始匯出 CSV 重新交給 AI 產生完整檔案。</p>
						<?php if ( ! empty( $import_result['blank_originals'] ) ) : ?>
							<details style="margin:8px 0 12px;"><summary>查看前 <?php echo esc_html( count( $import_result['blank_originals'] ) ); ?> 筆未翻譯原文</summary>
								<ul style="list-style:disc;padding-left:22px;">
									<?php foreach ( $import_result['blank_originals'] as $blank_text ) : ?><li><?php echo esc_html( $blank_text ); ?></li><?php endforeach; ?>
								</ul>
							</details>
						<?php endif; ?>
					<?php endif; ?>
				</div>
				<?php delete_transient( 'wu_ait_import_result' ); ?>
			<?php endif; ?>
		</div>

		<div id="tab-progress" class="wu-ait-tab" style="display:none;"> 			<h2>翻譯進度</h2> 			<p>全站已探索字串總數：<strong><?php echo esc_html( $discovered_count ); ?></strong></p>  			<?php if ( 0 === $discovered_count ) : ?> 				<p>尚未探索到任何字串。請先到「批次翻譯」分頁點擊「立即掃描全站」按鈕。</p> 			<?php elseif ( empty( $languages ) ) : ?> 				<p>偵測不到已發佈的次要語言。</p> 			<?php else : ?> 				<table class="widefat striped" style="max-width:600px;"> 					<thead><tr><th>語言</th><th>已翻譯 / 已探索</th><th>進度</th></tr></thead> 					<tbody> 						<?php foreach ( $languages as $lang ) : 							$progress = WU_AIT_TranslatePress_Bridge::get_progress_for_language( $lang ); 							$percent  = $progress['total'] > 0 ? round( ( $progress['translated'] / $progress['total'] ) * 100, 1 ) : 0; 							?> 							<tr> 								<td><?php echo esc_html( $lang ); ?></td> 								<td><?php echo esc_html( $progress['translated'] ); ?> / <?php echo esc_html( $progress['total'] ); ?></td> 								<td> 									<div style="background:#e2e4e7; border-radius:4px; height:16px; width:200px; overflow:hidden;"> 										<div style="background:#2271b1; height:16px; width:<?php echo esc_attr( $percent ); ?>%;"></div> 									</div> 									<?php echo esc_html( $percent ); ?>% 								</td> 							</tr> 						<?php endforeach; ?> 					</tbody> 				</table> 			<?php endif; ?> 		</div>  		<div id="tab-test" class="wu-ait-tab" style="display:none;"> 			<h2>測試翻譯</h2> 			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"> 				<?php wp_nonce_field( 'wu_ait_test_translate' ); ?> 				<input type="hidden" name="action" value="wu_ait_test_translate" /> 				<table class="form-table"> 					<tr> 						<th><label for="sample_text">測試原文</label></th> 						<td><input type="text" class="regular-text" name="sample_text" id="sample_text" value="歡迎使用我們的網站" /></td> 					</tr> 					<tr> 						<th><label for="sample_target">目標語言代碼</label></th> 						<td><input type="text" class="regular-text" name="sample_target" id="sample_target" value="en" placeholder="例如 en、ja、ko、vi" /></td> 					</tr> 				</table> 				<?php submit_button( '送出測試翻譯' ); ?> 			</form>  			<?php if ( $test_result ) : ?> 				<div class="notice notice-<?php echo $test_result['ok'] ? 'success' : 'error'; ?>"> 					<p><?php echo esc_html( wp_json_encode( $test_result, JSON_UNESCAPED_UNICODE ) ); ?></p> 				</div> 				<?php delete_transient( 'wu_ait_test_result' ); ?> 			<?php endif; ?> 		</div>  		<div id="tab-logs" class="wu-ait-tab" style="display:none;"> 			<h2>最近日誌（最多 200 筆）</h2> 			<table class="widefat striped"> 				<thead><tr><th style="width:160px;">時間</th><th style="width:80px;">等級</th><th>訊息</th></tr></thead> 				<tbody> 					<?php foreach ( $logs as $log ) : ?> 						<tr> 							<td><?php echo esc_html( $log['time'] ); ?></td> 							<td><?php echo esc_html( $log['level'] ); ?></td> 							<td><?php echo esc_html( $log['message'] ); ?></td> 						</tr> 					<?php endforeach; ?> 				</tbody> 			</table> 		</div> 	</div>  	<script> 	function wuAitTab(e, id) { 		e.preventDefault(); 		document.querySelectorAll('.wu-ait-tab').forEach(function (el) { el.style.display = 'none'; }); 		document.querySelectorAll('.nav-tab').forEach(function (el) { el.classList.remove('nav-tab-active'); }); 		document.getElementById('tab-' + id).style.display = 'block'; 		e.target.classList.add('nav-tab-active'); 	}  	function wuAitToggleProviderFields() { 		var selected = document.getElementById('provider').value; 		document.querySelectorAll('.wu-ait-provider-fields').forEach(function (el) { 			el.style.display = ( el.getAttribute('data-provider') === selected ) ? 'block' : 'none'; 		}); 	}  	function wuAitToggleScope(prefix) { 		var scope = document.querySelector('input[name="' + prefix + '_scope"]:checked').value; 		var rowId = ( prefix === 'export' ) ? 'wu-ait-export-post-row' : 'wu-ait-batch-post-row'; 		document.getElementById(rowId).style.display = ( scope === 'post' ) ? 'table-row' : 'none'; 	}  	function wuAitCleanModelInput(el) { 		var v = el.value; 		v = v.replace(/[\r\n\t]/g, ''); 		v = v.replace(/^["'\u201c\u201d\u2018\u2019\s]+|["'\u201c\u201d\u2018\u2019\s]+$/g, ''); 		v = v.replace(/\s+/g, ''); 		if (v.indexOf('/') !== -1) { 			var parts = v.split('/'); 			v = parts[parts.length - 1]; 		} 		el.value = v; 	}  	function wuAitVerifyProvider(providerKey) { 		var resultEl = document.getElementById('wu-ait-verify-result-' + providerKey); 		var apiKeyEl = document.getElementById('api_key_' + providerKey); 		var modelEl  = document.getElementById('model_' + providerKey);  		wuAitCleanModelInput(modelEl);  		resultEl.style.color = '#666'; 		resultEl.textContent = ' 驗證中...';  		var formData = new FormData(); 		formData.append('action', 'wu_ait_verify_provider'); 		formData.append('_ajax_nonce', '<?php echo esc_js( wp_create_nonce( 'wu_ait_verify_provider' ) ); ?>'); 		formData.append('provider', providerKey); 		formData.append('api_key', apiKeyEl.value); 		formData.append('model', modelEl.value);  		fetch(ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' }) 			.then(function (res) { return res.json(); }) 			.then(function (json) { 				if (json.success) { 					resultEl.style.color = '#1a7e28'; 					resultEl.textContent = ' ✓ ' + json.data.message; 				} else { 					resultEl.style.color = '#c0392b'; 					resultEl.textContent = ' ✗ ' + json.data.message; 				} 			}) 			.catch(function (err) { 				resultEl.style.color = '#c0392b'; 				resultEl.textContent = ' ✗ 驗證請求失敗：' + err; 			}); 	}  	function wuAitScanSitewide() { 		var btn      = document.getElementById('wu-ait-scan-btn'); 		var resultEl = document.getElementById('wu-ait-scan-result'); 		var countEl  = document.getElementById('wu-ait-discovered-count');  		btn.disabled = true; 		btn.textContent = '掃描中，請稍候...'; 		resultEl.style.color = '#666'; 		resultEl.textContent = '';  		var formData = new FormData(); 		formData.append('action', 'wu_ait_scan_sitewide'); 		formData.append('_ajax_nonce', '<?php echo esc_js( wp_create_nonce( 'wu_ait_scan_sitewide' ) ); ?>');  		fetch(ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' }) 			.then(function (res) { return res.json(); }) 			.then(function (json) { 				btn.disabled = false; 				btn.textContent = '立即掃描全站（不需前台瀏覽、不需進入文章編輯頁）'; 				if (json.success) { 					resultEl.style.color = '#1a7e28'; 					resultEl.textContent = '✓ ' + json.data.message; 					var match = json.data.message.match(/總數：(\d+)/); 					if (match && countEl) { countEl.textContent = match[1]; } 				} else { 					resultEl.style.color = '#c0392b'; 					resultEl.textContent = '✗ ' + json.data.message; 				} 			}) 			.catch(function (err) { 				btn.disabled = false; 				btn.textContent = '立即掃描全站（不需前台瀏覽、不需進入文章編輯頁）'; 				resultEl.style.color = '#c0392b'; 				resultEl.textContent = '✗ 請求失敗：' + err; 			}); 	}  	function wuAitRefreshPrompt() { 		var lang     = document.getElementById('export_lang').value; 		var promptEl = document.getElementById('wu-ait-ai-prompt'); 		if (!promptEl) { return; }  		var formData = new FormData(); 		formData.append('action', 'wu_ait_get_ai_prompt'); 		formData.append('_ajax_nonce', '<?php echo esc_js( wp_create_nonce( 'wu_ait_get_ai_prompt' ) ); ?>'); 		formData.append('target_lang', lang);  		fetch(ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' }) 			.then(function (res) { return res.json(); }) 			.then(function (json) { 				if (json.success) { 					promptEl.value = json.data.prompt; 				} 			}); 	}  	function wuAitCopyPrompt() { 		var promptEl = document.getElementById('wu-ait-ai-prompt'); 		var resultEl = document.getElementById('wu-ait-copy-result');  		promptEl.select(); 		promptEl.setSelectionRange(0, 999999);  		navigator.clipboard.writeText(promptEl.value).then(function () { 			resultEl.style.color = '#1a7e28'; 			resultEl.textContent = '✓ 已複製到剪貼簿，可直接貼給 AI'; 			setTimeout(function () { resultEl.textContent = ''; }, 3000); 		}).catch(function () { 			document.execCommand('copy'); 			resultEl.style.color = '#1a7e28'; 			resultEl.textContent = '✓ 已複製到剪貼簿'; 			setTimeout(function () { resultEl.textContent = ''; }, 3000); 		}); 	}  	document.addEventListener('DOMContentLoaded', function () { 		wuAitToggleProviderFields(); 		document.querySelectorAll('.wu-ait-model-input').forEach(function (el) { wuAitCleanModelInput(el); });  		var scanBtn = document.getElementById('wu-ait-scan-btn'); 		if ( scanBtn ) { 			scanBtn.addEventListener('click', wuAitScanSitewide); 		} 	}); 	</script> 	<style> 	.wu-ait-verify-result { margin-left: 8px; font-size: 13px; } 	</style> 	<?php }  

/**
 * Toolbox modules are loaded after WordPress activation hooks have run.
 * Ensure the original five-minute worker is still available.
 */
add_action( 'init', static function (): void {
    if ( ! wp_next_scheduled( WU_AIT_CRON_HOOK ) ) {
        wp_schedule_event( time() + 60, 'wu_ait_five_minutes', WU_AIT_CRON_HOOK );
    }
}, 20 );

/**
 * Keep the original settings controls intact while matching the Toolbox admin shell.
 */
add_action( 'admin_head-options-general.php', static function (): void {
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( 'wu-ai-translate' !== $page ) {
        return;
    }
    ?>
    <style>
        body.settings_page_wu-ai-translate #wpbody-content > .wrap { max-width: 1180px; margin: 28px auto; padding: 28px; background: #fff; border: 1px solid #dcdcde; border-radius: 10px; box-sizing: border-box; }
        body.settings_page_wu-ai-translate #wpbody-content > .wrap > h1 { margin: -28px -28px 26px; padding: 22px 28px; color: #fff; background: linear-gradient(135deg, #0b315e, #1e5e9d); border-radius: 9px 9px 0 0; }
        body.settings_page_wu-ai-translate .nav-tab-wrapper { margin: 24px 0; }
        body.settings_page_wu-ai-translate .form-table { max-width: 980px; }
    </style>
    <?php
} );
