--TEST--
curl_share_init()/curl_share_setopt()/curl_share_close() lifecycle (#6322, ext/curl/curl_share.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

foreach (['curl_share_init', 'curl_share_setopt', 'curl_share_close'] as $fn) {
    echo $fn, ':', function_exists($fn) ? 'yes' : 'no', "\n";
}

$share = curl_share_init();
echo get_class($share), "\n";
echo curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS) ? "share-dns\n" : "share-dns-fail\n";
curl_share_close($share);
curl_share_close($share);
echo "double-close-ok\n";

enum E: string { case A = 'x'; }
$share3 = curl_share_init();
try {
    curl_share_setopt($share3, E::A, CURL_LOCK_DATA_DNS);
    echo "enum-option-fail\n";
} catch (TypeError $e) {
    echo "enum-option-ok\n";
}
?>
--EXPECT--
curl_share_init:yes
curl_share_setopt:yes
curl_share_close:yes
CurlShareHandle
share-dns
double-close-ok
enum-option-ok
