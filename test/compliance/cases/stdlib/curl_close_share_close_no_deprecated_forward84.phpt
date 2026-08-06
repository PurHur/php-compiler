--TEST--
curl_close()/curl_share_close() silent under PROFILE=8.4 (#28133, ext/curl/curl.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $errno, string $msg) use (&$seen): bool {
    if (E_DEPRECATED === $errno || E_USER_DEPRECATED === $errno) {
        $seen[] = $msg;
    }
    return true;
});

curl_close(curl_init());
$sh = curl_share_init();
curl_share_close($sh);
echo 'count=', count($seen), "\n";
echo 'done', "\n";
--EXPECT--
count=0
done
