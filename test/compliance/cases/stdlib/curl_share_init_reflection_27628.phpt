--TEST--
curl_share_init() Reflection return CurlShareHandle (#27628, ext/curl/curl.stub.php)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
$r = new ReflectionFunction('curl_share_init');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
echo 'argc=', $r->getNumberOfParameters(), "\n";
$sh = curl_share_init();
echo 'type=', get_debug_type($sh), "\n";
curl_share_close($sh);
?>
--EXPECT--
ret=CurlShareHandle
argc=0
type=CurlShareHandle
