<?php
/**
 * Issue #26263 — curl_upkeep / curl_share_init + CURLOPT_UPKEEP_INTERVAL_MS (php-src-strict).
 *
 * When ext/curl is loaded (host Zend or PHP_COMPILER_ENABLE_CURL=1), symbols match php-src.
 */
declare(strict_types=1);

echo 'curl_upkeep=', function_exists('curl_upkeep') ? 'Y' : 'N', "\n";
echo 'curl_share_init=', function_exists('curl_share_init') ? 'Y' : 'N', "\n";
echo 'CURLOPT_UPKEEP_INTERVAL_MS=', defined('CURLOPT_UPKEEP_INTERVAL_MS')
    ? (string) constant('CURLOPT_UPKEEP_INTERVAL_MS')
    : 'N', "\n";

if (!function_exists('curl_upkeep')) {
    exit(0);
}

$ch = curl_init();
echo 'setopt=', curl_setopt($ch, CURLOPT_UPKEEP_INTERVAL_MS, 30000) ? 'Y' : 'N', "\n";
echo 'upkeep=', var_export(curl_upkeep($ch), true), "\n";
$share = curl_share_init();
echo 'share=', get_class($share), "\n";
curl_share_close($share);
curl_close($ch);
