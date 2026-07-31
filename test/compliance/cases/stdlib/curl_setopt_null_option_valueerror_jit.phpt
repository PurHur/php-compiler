--TEST--
stdlib curl_setopt(null option) — ValueError invalid cURL option — JIT (#21878, ext/curl/interface.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--JIT--
--FILE--
<?php
$ch = curl_init();
try {
    curl_setopt($ch, null, 0);
    echo "miss\n";
} catch (ValueError $e) {
    echo 'VALUEERROR:', $e->getMessage(), "\n";
}
?>
--EXPECT--
VALUEERROR:curl_setopt(): Argument #2 ($option) is not a valid cURL option
