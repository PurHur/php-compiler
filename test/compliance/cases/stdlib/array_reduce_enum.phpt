--TEST--
stdlib array_reduce() — enum case identity preserved through closure callback (#5626, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

$cases = [E::A, E::B];
$r = array_reduce($cases, function ($carry, $item) {
    return $carry === null ? $item : $item;
});
if (!($r instanceof E)) {
    throw new RuntimeException('expected enum object, got '.get_debug_type($r));
}
echo $r->name, "\n";

$first = array_reduce([E::A, E::B], fn ($c, $i) => $c === null ? $i : $c);
echo $first instanceof E ? $first->name : get_debug_type($first), "\n";

$collected = array_reduce([E::A, E::B], function ($carry, $item) {
    $carry[] = $item;
    return $carry;
}, []);
foreach ($collected as $i => $v) {
    if (!($v instanceof E)) {
        throw new RuntimeException('expected enum object at index '.$i.', got '.get_debug_type($v));
    }
    echo $i, ':', $v->name, "\n";
}
?>
--EXPECT--
B
A
0:A
1:B
