--TEST--
stdlib curl_setopt(null option) — ValueError invalid cURL option (#21878, ext/curl/interface.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
$ch = curl_init();
foreach ([null, 0, 999999] as $i => $opt) {
    try {
        curl_setopt($ch, $opt, 0);
        echo $i, ": miss\n";
    } catch (ValueError $e) {
        echo $i, ':VALUEERROR:', $e->getMessage(), "\n";
    } catch (TypeError $e) {
        echo $i, ':TYPEERROR:', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
0:VALUEERROR:curl_setopt(): Argument #2 ($option) is not a valid cURL option
1:VALUEERROR:curl_setopt(): Argument #2 ($option) is not a valid cURL option
2:VALUEERROR:curl_setopt(): Argument #2 ($option) is not a valid cURL option
