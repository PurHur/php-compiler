--TEST--
stdlib ArrayObject foreach / iterator_to_array (#12311, ext/spl/spl_array.c)
--FILE--
<?php
$ao = new ArrayObject(['a' => 1, 'b' => 2]);
foreach ($ao as $k => $v) {
    echo "$k=$v\n";
}
$values = iterator_to_array($ao, false);
echo '[' . implode(',', $values) . "]\n";
echo "ok\n";
--EXPECT--
a=1
b=2
[1,2]
ok
