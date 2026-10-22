<?php
/** Deterministic PHP-token namespace transformation; CLI build tool, not runtime.
 * Run on a clean copy of the pinned invoice source. No dependency upgrades or
 * wire-format/option/meta substitutions. Already-scoped files remain unchanged.
 */
if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80000) exit(1);
$root = dirname(__DIR__) . '/modules/';
$sdk = $root . 'ecpay-invoice/src/apis/EcpayInvoiceSDK.php';
$sdkClasses = [];
$tokens = token_get_all(file_get_contents($sdk));
$nextClass = false;
foreach ($tokens as $token) {
    if (!is_array($token)) continue;
    if ($token[0] === T_CLASS) { $nextClass = true; continue; }
    if ($nextClass && $token[0] === T_STRING) {
        if (strpos($token[1], 'WUTMInvoiceSDK_') !== 0) $sdkClasses[] = $token[1];
        else $sdkClasses[] = substr($token[1], strlen('WUTMInvoiceSDK_'));
        $nextClass = false;
    }
}
usort($sdkClasses, static function($a,$b){return strlen($b)-strlen($a);});
$sdkPattern = '/(?<![A-Za-z0-9_])(' . implode('|', array_map(static function($s){return preg_quote($s, '/');}, $sdkClasses)) . ')(?![A-Za-z0-9_])/i';
function wutm_scope_name(string $name, bool $namespace): string {
    if (!preg_match('/^(\\\\?)(GuzzleHttp|Psr|InvoicePorter|ODS|Symfony)(?=\\\\|$)/', $name)) return $name;
    $name = ltrim($name, '\\');
    return ($namespace ? '' : '\\') . 'WUTMInvoiceDeps\\' . $name;
}
function wutm_scope_string(string $text): string {
    return preg_replace_callback('/(?<![A-Za-z0-9_])(GuzzleHttp|Psr|InvoicePorter|ODS|Symfony)(\\\\+|(?=[\'\"]))/u', static function($m){
        $slash = isset($m[2]) && $m[2] !== '' ? $m[2] : '\\';
        return 'WUTMInvoiceDeps' . $slash . $m[0];
    }, $text);
}
$changed = 0;
foreach (['ezpay-invoice','ecpay-invoice'] as $provider) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $provider, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') continue;
        $source = file_get_contents($file->getPathname());
        $out = ''; $isNamespace = false;
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) { $out .= $token; if ($token === ';' || $token === '{') $isNamespace = false; continue; }
            [$id, $text] = $token;
            if ($id === T_NAMESPACE) $isNamespace = true;
            if (in_array($id,[T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED,T_STRING],true)) {
                $text = wutm_scope_name($text, $isNamespace);
                if ($provider === 'ecpay-invoice') $text = preg_replace($sdkPattern, 'WUTMInvoiceSDK_$1', $text);
            } elseif (in_array($id,[T_CONSTANT_ENCAPSED_STRING,T_ENCAPSED_AND_WHITESPACE],true)) {
                // Skip already-prefixed roots in quoted function/class maps.
                if (strpos($text, 'WUTMInvoiceDeps') === false) $text = wutm_scope_string($text);
                if ($provider === 'ecpay-invoice') {
                    $text = str_replace(["'ECPay_INVOICE_VOID_SE'", "'ECPay_ALLOWANCE_SEARC'", "'ECPay_ALLOWANCE_VOID_'", "'ECPay_CHECK_MOBILE_BA'"], ["'WUTMInvoiceSDK_ECPay_INVOICE_VOID_SEARCH'", "'WUTMInvoiceSDK_ECPay_ALLOWANCE_SEARCH'", "'WUTMInvoiceSDK_ECPay_ALLOWANCE_VOID_SEARCH'", "'WUTMInvoiceSDK_ECPay_CHECK_MOBILE_BARCODE'"], $text);
                    $text = preg_replace($sdkPattern, 'WUTMInvoiceSDK_$1', $text);
                    if ($text === "'ECPay_'" || $text === '"ECPay_"') $text = str_replace('ECPay_', 'WUTMInvoiceSDK_ECPay_', $text);
                }
            }
            $out .= $text;
        }
        if ($out !== $source) { file_put_contents($file->getPathname(), $out); ++$changed; }
    }
}
echo 'Scoped invoice PHP files: ' . $changed . "\n";
