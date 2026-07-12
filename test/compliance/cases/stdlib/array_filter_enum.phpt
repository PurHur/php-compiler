--TEST--
stdlib array_filter() — enum case objects preserved in callback (#5564, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

$out = array_filter([E::A, E::B], fn ($x) => $x === E::A);
foreach ($out as $i => $v) {
    if (!($v instanceof E)) {
        throw new RuntimeException('expected enum object at index '.$i.', got '.get_debug_type($v));
    }
    echo $i, ':', $v->name, "\n";
}
?>
--EXPECT--
0:A
