--TEST--
AOT: ArrayIterator/plain HT foreach includes string keys after packed (#34977)
--FILE--
<?php
$a = new ArrayIterator([1, 2, 'x' => 3]);
$a->append(4);
echo $a->count(), '|';
foreach ($a as $k => $v) {
    echo $k, '=', $v, ';';
}
echo $a->key() === null ? 'null' : $a->key();
echo "\n";
$b = [1, 2, 'y' => 5];
echo count($b), '|';
foreach ($b as $k => $v) {
    echo $k, '=', $v, ';';
}
echo "\n";
--EXPECT--
4|0=1;1=2;2=4;x=3;null
3|0=1;1=2;y=5;
