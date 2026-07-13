--TEST--
stdlib explode null string operand TypeError (#18600, ext/standard/string.c)
--SKIPIF--
<?php die('skip — compiler VM compliance via VMTest, not Zend CLI'); ?>
--FILE--
<?php
try {
    explode(',', null);
    echo "explode: NO_THROW\n";
} catch (TypeError $e) {
    echo 'explode: '.$e->getMessage()."\n";
}
?>
--EXPECT--
explode: explode(): Argument #2 ($string) must be of type string, null given
