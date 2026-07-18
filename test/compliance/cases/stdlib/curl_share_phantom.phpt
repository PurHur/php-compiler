--TEST--
stdlib curl_share_* / CurlShareHandle withheld without ext/curl — no phantom (#19728, re-#12117)
--FILE--
<?php
declare(strict_types=1);

echo 'curl_loaded=', (int) extension_loaded('curl'), "\n";
echo 'curl_init=', (int) function_exists('curl_init'), "\n";
echo 'curl_share_init=', (int) function_exists('curl_share_init'), "\n";
echo 'curl_share_setopt=', (int) function_exists('curl_share_setopt'), "\n";
echo 'curl_share_close=', (int) function_exists('curl_share_close'), "\n";
echo 'curl_share_errno=', (int) function_exists('curl_share_errno'), "\n";
echo 'curl_share_strerror=', (int) function_exists('curl_share_strerror'), "\n";
echo 'CurlHandle=', (int) class_exists('CurlHandle', false), "\n";
echo 'CurlShareHandle=', (int) class_exists('CurlShareHandle', false), "\n";
?>
--EXPECT--
curl_loaded=0
curl_init=0
curl_share_init=0
curl_share_setopt=0
curl_share_close=0
curl_share_errno=0
curl_share_strerror=0
CurlHandle=0
CurlShareHandle=0
