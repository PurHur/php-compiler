<?php
// Repro #20531 — curl_share_errno / curl_share_strerror (php-src ext/curl/share.c)
declare(strict_types=1);

$failed = 0;
foreach (['curl_share_errno', 'curl_share_strerror'] as $fn) {
    if (!function_exists($fn)) {
        echo "$fn: missing\n";
        ++$failed;
    }
}

$share = curl_share_init();
if (0 !== curl_share_errno($share)) {
    echo "fresh errno expected 0\n";
    ++$failed;
}
if ('No error' !== curl_share_strerror(0)) {
    echo "strerror(0) mismatch\n";
    ++$failed;
}
if ('Unknown share option' !== curl_share_strerror(1)) {
    echo "strerror(1) mismatch\n";
    ++$failed;
}
try {
    curl_share_setopt($share, 99999, 1);
    echo "bad option uncaught\n";
    ++$failed;
} catch (ValueError $e) {
    if (1 !== curl_share_errno($share)) {
        echo "errno after bad option expected 1, got ", curl_share_errno($share), "\n";
        ++$failed;
    }
}
curl_share_close($share);
echo $failed > 0 ? "FAIL\n" : "ok\n";
exit($failed > 0 ? 1 : 0);
