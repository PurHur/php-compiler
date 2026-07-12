--TEST--
stdlib nl2br()/wordwrap()/stripslashes() JIT — null operand TypeError (#18358, ext/standard/string.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
foreach (['nl2br', 'wordwrap', 'stripslashes'] as $fn) {
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
wordwrap: wordwrap(): Argument #1 ($string) must be of type string, null given
stripslashes: stripslashes(): Argument #1 ($string) must be of type string, null given
