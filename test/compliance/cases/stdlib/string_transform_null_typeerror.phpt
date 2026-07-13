--TEST--
stdlib nl2br/trim family — null operand TypeError on typed string params (#11322, #18598, ext/standard/string.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
foreach (['nl2br', 'chop', 'rtrim', 'ltrim', 'trim', 'wordwrap', 'ucfirst', 'lcfirst', 'ucwords'] as $fn) {
    try {
        $fn(null);
        echo "$fn: NO_THROW\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
echo trim('  x  '), "\n";
?>
--EXPECT--
nl2br: nl2br(): Argument #1 ($string) must be of type string, null given
chop: chop(): Argument #1 ($string) must be of type string, null given
rtrim: rtrim(): Argument #1 ($string) must be of type string, null given
ltrim: ltrim(): Argument #1 ($string) must be of type string, null given
trim: trim(): Argument #1 ($string) must be of type string, null given
wordwrap: NO_THROW
ucfirst: NO_THROW
lcfirst: NO_THROW
ucwords: NO_THROW
x
