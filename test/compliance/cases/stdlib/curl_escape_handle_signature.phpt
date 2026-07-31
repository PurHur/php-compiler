--TEST--
stdlib curl_escape()/curl_unescape() require CurlHandle (#20493, re-#13588, php-src-strict)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
$ch = curl_init();
var_export(curl_escape($ch, 'a b'));
echo "\n";
var_export(curl_unescape($ch, 'a%20b'));
echo "\n";
try {
    curl_escape('a b');
    echo "1arg_uncaught\n";
} catch (ArgumentCountError $e) {
    echo "1arg ", get_class($e), "\n";
}
try {
    curl_escape('a b', 'x');
    echo "badhandle_uncaught\n";
} catch (TypeError $e) {
    echo "badhandle ", $e->getMessage(), "\n";
}
--EXPECT--
'a%20b'
'a b'
1arg ArgumentCountError
badhandle curl_escape(): Argument #1 ($handle) must be of type CurlHandle, string given
