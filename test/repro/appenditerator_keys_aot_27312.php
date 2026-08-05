<?php
// AOT: AppendIterator preserves inner keys across append (#27312)
$a = new AppendIterator();
$a->append(new ArrayIterator([1, 2]));
$a->append(new ArrayIterator([3]));
$out = [];
foreach ($a as $k => $v) {
    $out[] = "$k:$v";
}
echo implode(',', $out), "\n";
