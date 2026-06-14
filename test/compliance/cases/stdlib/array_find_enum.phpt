--TEST--
stdlib array_find() — enum case preserved in predicate callback (#5638, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$found = array_find([E::A, E::B], function ($v) {
    if (!($v instanceof E)) {
        throw new RuntimeException('expected enum object, got ' . get_debug_type($v));
    }
    return $v === E::B;
});
var_dump($found);
?>
--EXPECT--
enum(E::B)
