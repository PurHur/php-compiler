--TEST--
stdlib array_walk_recursive() — nested enum case objects passed to callback (#5567, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

$nested = ['x' => [E::A], 'y' => E::B];
$seen = [];
array_walk_recursive($nested, function (&$v, $k) use (&$seen): void {
    if (!($v instanceof E)) {
        throw new RuntimeException('expected enum object at key '.$k.', got '.get_debug_type($v));
    }
    $seen[] = ($v === E::A ? 'A' : ($v === E::B ? 'B' : $v->name));
    echo $k, ':', $v->name, "\n";
});

echo 'seen:', implode(',', $seen), "\n";
echo 'nested_x0:', ($nested['x'][0] === E::A ? 'A' : get_debug_type($nested['x'][0])), "\n";
echo 'nested_y:', ($nested['y'] === E::B ? 'B' : get_debug_type($nested['y'])), "\n";
?>
--EXPECT--
0:A
y:B
seen:A,B
nested_x0:A
nested_y:B
