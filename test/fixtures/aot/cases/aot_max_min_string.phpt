--TEST--
AOT: max()/min() on string arguments (#23951)
--FILE--
<?php
$a = 'xy';
$b = 'zw';
echo max($a, $b), "\n";
echo min($a, $b), "\n";
echo max($a, $b, 'zz'), "\n";
echo min('aa', $a, $b), "\n";
$r = ['a' => 'xy', 'b' => 'zw'];
echo max($r['a'], $r['b']), "\n";
echo min($r['a'], $r['b']), "\n";
echo max(3, 7), "\n";
--EXPECT--
zw
xy
zz
aa
zw
xy
7
