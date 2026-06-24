--TEST--
stdlib nl2br/trim family JIT — null operand coerces when caller non-strict (#11322)
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
nl2br: NO_THROW
trim: NO_THROW
ucfirst: NO_THROW
