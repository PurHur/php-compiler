--TEST--
Stdlib: usort()/uasort() equal-comparator ties preserve order (#13762, ext/standard/array.c)
--FILE--
<?php
$rows = [
    ['id' => 'a', 'v' => 1],
    ['id' => 'c', 'v' => 2],
    ['id' => 'b', 'v' => 2],
];
usort($rows, static fn ($x, $y) => $x['v'] <=> $y['v']);
echo implode(',', array_column($rows, 'id')), "\n";

$assoc = ['a' => 1, 'c' => 2, 'b' => 2];
uasort($assoc, static fn ($x, $y) => $x <=> $y);
echo implode(',', array_keys($assoc)), "\n";

$large = [];
for ($i = 0; $i < 20; ++$i) {
    $large[] = ['id' => chr(97 + $i), 'v' => $i % 3];
}
usort($large, static fn ($x, $y) => $x['v'] <=> $y['v']);
echo implode(',', array_column($large, 'id')), "\n";
?>
--EXPECT--
a,c,b
a,c,b
a,d,g,j,m,p,s,b,e,h,k,n,q,t,c,f,i,l,o,r
