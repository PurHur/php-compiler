--TEST--
stdlib utf8_encode/utf8_decode null operand TypeError JIT (#18591, ext/standard/basic_functions.c)
--SKIPIF--
<?php die('skip — compiler JIT compliance via JITTest, not Zend CLI'); ?>
--FILE--
<?php
foreach (['utf8_encode', 'utf8_decode'] as $fn) {
    try {
        $fn(null);
        echo "$fn: NO_THROW\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
utf8_encode: utf8_encode(): Argument #1 ($string) must be of type string, null given
utf8_decode: utf8_decode(): Argument #1 ($string) must be of type string, null given
