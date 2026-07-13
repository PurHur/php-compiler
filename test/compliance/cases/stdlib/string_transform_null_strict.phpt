--TEST--
stdlib wordwrap()/stripslashes() — null operand coerces to empty string (#18483, ext/standard/string.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
foreach (['wordwrap', 'stripslashes'] as $fn) {
    try {
        $fn(null);
        echo "$fn: NO_THROW\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
wordwrap: NO_THROW
stripslashes: NO_THROW
