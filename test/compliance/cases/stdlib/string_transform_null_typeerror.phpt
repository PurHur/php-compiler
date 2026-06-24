--TEST--
stdlib nl2br/trim family — null operand TypeError (#11171, ext/standard/string.c)
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
--EXPECTF--
%A
nl2br: nl2br(): Argument #1 ($string) must be of type string, null given
chop: chop(): Argument #1 ($string) must be of type string, null given
rtrim: rtrim(): Argument #1 ($string) must be of type string, null given
ltrim: ltrim(): Argument #1 ($string) must be of type string, null given
trim: trim(): Argument #1 ($string) must be of type string, null given
wordwrap: wordwrap(): Argument #1 ($string) must be of type string, null given
ucfirst: ucfirst(): Argument #1 ($string) must be of type string, null given
lcfirst: lcfirst(): Argument #1 ($string) must be of type string, null given
ucwords: ucwords(): Argument #1 ($string) must be of type string, null given
x
