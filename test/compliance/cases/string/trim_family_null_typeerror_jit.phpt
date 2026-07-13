--TEST--
stdlib trim family null operand TypeError JIT (#18598, ext/standard/string.c)
--SKIPIF--
<?php die('skip — compiler JIT compliance via JITTest, not Zend CLI'); ?>
--FILE--
<?php
foreach (['trim', 'ltrim', 'rtrim', 'chop'] as $fn) {
    try {
        $fn(null);
        echo "$fn: NO_THROW\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
trim: trim(): Argument #1 ($string) must be of type string, null given
ltrim: ltrim(): Argument #1 ($string) must be of type string, null given
rtrim: rtrim(): Argument #1 ($string) must be of type string, null given
chop: chop(): Argument #1 ($string) must be of type string, null given
