--TEST--
stdlib array_walk() JIT — enum case objects passed to callback by reference (#5567)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

$arr = [E::A, E::B];
$seen = [];
array_walk($arr, function (&$v, $k) use (&$seen): void {
    if (!($v instanceof E)) {
        throw new RuntimeException('expected enum object at key '.$k.', got '.get_debug_type($v));
    }
    $seen[$k] = $v->name;
    echo $k, ':', ($v === E::A ? 'A' : ''), ($v === E::B ? 'B' : ''), ':', $v->name, "\n";
});

echo 'seen:', implode(',', $seen), "\n";

$arr2 = [E::A];
array_walk($arr2, function (&$v): void {
    $v = E::B;
});
echo 'replaced:', ($arr2[0] === E::B ? 'B' : get_debug_type($arr2[0])), "\n";
?>
--EXPECT--
0:A:A
1:B:B
seen:A,B
replaced:B
