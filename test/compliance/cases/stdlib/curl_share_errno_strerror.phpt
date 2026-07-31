--TEST--
curl_share_errno()/curl_share_strerror() — share error surface (#20531, ext/curl/share.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

foreach (['curl_share_errno', 'curl_share_strerror'] as $fn) {
    echo $fn, ':', function_exists($fn) ? 'yes' : 'no', "\n";
}

$share = curl_share_init();
echo 'errno0=', curl_share_errno($share), "\n";
echo 'strerror0=', curl_share_strerror(0), "\n";
echo 'strerror1=', curl_share_strerror(1), "\n";
echo 'strerror999=', curl_share_strerror(999), "\n";

curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
echo 'errno-after-ok=', curl_share_errno($share), "\n";

try {
    curl_share_setopt($share, 99999, 1);
    echo "bad-option-uncaught\n";
} catch (ValueError $e) {
    echo 'bad-option-ok errno=', curl_share_errno($share), "\n";
}

try {
    curl_share_errno(null);
    echo "null-uncaught\n";
} catch (TypeError $e) {
    echo "null-typeerror-ok\n";
}

curl_share_close($share);
?>
--EXPECT--
curl_share_errno:yes
curl_share_strerror:yes
errno0=0
strerror0=No error
strerror1=Unknown share option
strerror999=CURLSHcode unknown
errno-after-ok=0
bad-option-ok errno=1
null-typeerror-ok
