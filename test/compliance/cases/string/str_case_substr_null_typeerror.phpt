--TEST--
stdlib strtolower/strtoupper/substr null operand TypeError (#18599, ext/standard/string.c)
--SKIPIF--
<?php die('skip — compiler VM compliance via VMTest, not Zend CLI'); ?>
--FILE--
<?php
foreach (['strtolower', 'strtoupper'] as $fn) {
    try {
        $fn(null);
        echo "$fn: NO_THROW\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
try {
    substr(null, 0);
    echo "substr: NO_THROW\n";
} catch (TypeError $e) {
    echo 'substr: '.$e->getMessage()."\n";
}
?>
--EXPECT--
strtolower: strtolower(): Argument #1 ($string) must be of type string, null given
strtoupper: strtoupper(): Argument #1 ($string) must be of type string, null given
substr: substr(): Argument #1 ($string) must be of type string, null given
