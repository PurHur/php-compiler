--TEST--
stdlib array_find_key() — enum case preserved in predicate callback (#5638)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$key = array_find_key([E::A, E::B], function ($v) {
    if (!($v instanceof E)) {
        throw new RuntimeException('expected enum object, got ' . get_debug_type($v));
    }
    return $v === E::B;
});
var_dump($key);
?>
--EXPECT--
int(1)
