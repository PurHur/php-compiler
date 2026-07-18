--TEST--
stdlib array_walk_recursive() JIT — nested enum case objects (#5567)
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
?>
--EXPECT--
0:A
y:B
seen:A,B
