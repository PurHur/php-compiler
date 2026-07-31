--TEST--
curl_multi_select() float/int timeout returns int (#21569, ext/curl/multi.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

$mh = curl_multi_init();
$r0 = curl_multi_select($mh);
echo 'default=', gettype($r0), ':', (int) is_int($r0), "\n";
$r1 = curl_multi_select($mh, 0.01);
echo 'float=', gettype($r1), ':', (int) is_int($r1), "\n";
$r2 = curl_multi_select($mh, 0);
echo 'int=', gettype($r2), ':', (int) is_int($r2), "\n";
try {
    curl_multi_select();
    echo "arity0-uncaught\n";
} catch (ArgumentCountError $e) {
    echo 'arity0=ok', "\n";
}
try {
    curl_multi_select($mh, 0.01, 1);
    echo "arity3-uncaught\n";
} catch (ArgumentCountError $e) {
    echo 'arity3=ok', "\n";
}
curl_multi_close($mh);
?>
--EXPECT--
default=integer:1
float=integer:1
int=integer:1
arity0=ok
arity3=ok
