--TEST--
stdlib nl2br/trim family JIT — null operand TypeError on typed string params (#11322, #18598)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
foreach (['nl2br', 'trim', 'ucfirst'] as $fn) {
    try {
        $fn(null);
        echo "$fn: NO_THROW\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
nl2br: nl2br(): Argument #1 ($string) must be of type string, null given
trim: trim(): Argument #1 ($string) must be of type string, null given
ucfirst: NO_THROW
