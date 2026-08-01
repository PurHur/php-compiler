--TEST--
curl CURLOPT_UPKEEP_INTERVAL_MS + curl_upkeep (#26263, #23899, ext/curl/curl.stub.php)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

echo 'exists_upkeep=', (int) function_exists('curl_upkeep'), "\n";
echo 'exists_share=', (int) function_exists('curl_share_init'), "\n";
echo 'CURLOPT_UPKEEP_INTERVAL_MS=', defined('CURLOPT_UPKEEP_INTERVAL_MS')
    ? (string) CURLOPT_UPKEEP_INTERVAL_MS
    : 'UNDEF', "\n";

$ch = curl_init();
echo curl_setopt($ch, CURLOPT_UPKEEP_INTERVAL_MS, 30000) ? "setopt-ok\n" : "setopt-fail\n";
$ok = curl_upkeep($ch);
echo 'upkeep_type=', gettype($ok), "\n";
echo 'upkeep_ok=', $ok ? '1' : '0', "\n";

$share = curl_share_init();
echo 'share=', $share instanceof CurlShareHandle ? 'CurlShareHandle' : get_debug_type($share), "\n";
curl_share_close($share);
curl_close($ch);
?>
--EXPECT--
exists_upkeep=1
exists_share=1
CURLOPT_UPKEEP_INTERVAL_MS=281
setopt-ok
upkeep_type=boolean
upkeep_ok=1
share=CurlShareHandle
