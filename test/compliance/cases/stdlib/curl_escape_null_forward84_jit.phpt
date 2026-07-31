--TEST--
stdlib curl_escape()/curl_unescape(null) — TypeError on 8.4 forward profile JIT (#20695, ext/curl/interface.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$ch = curl_init();
try {
    curl_escape($ch, null);
    echo "escape_uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    curl_unescape($ch, null);
    echo "unescape_uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
curl_escape(): Argument #2 ($string) must be of type string, null given
curl_unescape(): Argument #2 ($string) must be of type string, null given
