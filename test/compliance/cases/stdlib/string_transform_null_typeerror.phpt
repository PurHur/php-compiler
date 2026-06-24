--TEST--
stdlib nl2br/trim family — null operand coerces when caller non-strict (#11322, ext/standard/string.c)
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
nl2br: NO_THROW
chop: NO_THROW
rtrim: NO_THROW
ltrim: NO_THROW
trim: NO_THROW
wordwrap: NO_THROW
ucfirst: NO_THROW
lcfirst: NO_THROW
ucwords: NO_THROW
x
