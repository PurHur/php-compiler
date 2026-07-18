<?php
// Repro #20520 — curl_multi_get_handles (PHP 8.5)
declare(strict_types=1);

echo 'exists=', function_exists('curl_multi_get_handles') ? 'Y' : 'N', PHP_EOL;
if (!function_exists('curl_multi_get_handles')) {
    exit(1);
}

$mh = curl_multi_init();
$ch = curl_init('https://example.com');
curl_multi_add_handle($mh, $ch);
$hs = curl_multi_get_handles($mh);
echo 'count=', count($hs), PHP_EOL;
echo 'same=', ($hs[0] === $ch) ? 'Y' : 'N', PHP_EOL;
curl_multi_remove_handle($mh, $ch);
echo 'after=', count(curl_multi_get_handles($mh)), PHP_EOL;
curl_multi_close($mh);
curl_close($ch);
