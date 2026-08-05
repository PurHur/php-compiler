--TEST--
AOT: AppendIterator preserves inner keys across append (#27312)
--FILE--
<?php
$a = new AppendIterator();
$a->append(new ArrayIterator([1, 2]));
$a->append(new ArrayIterator([3]));
$out = [];
foreach ($a as $k => $v) {
    $out[] = "$k:$v";
}
echo implode(',', $out), "\n";
--EXPECT--
0:1,1:2,0:3
