--TEST--
stdlib array_map() — enum case identity preserved through closure callback (#5564, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

$out = array_map(fn ($x) => $x, [E::A, E::B]);
foreach ($out as $i => $v) {
    if (!($v instanceof E)) {
        throw new RuntimeException('expected enum object at index '.$i.', got '.get_debug_type($v));
    }
    echo $i, ':', $v->name, "\n";
}

echo array_map(fn ($x) => $x === E::A ? 'match' : 'nomatch', [E::A])[0], "\n";
?>
--EXPECT--
0:A
1:B
match
