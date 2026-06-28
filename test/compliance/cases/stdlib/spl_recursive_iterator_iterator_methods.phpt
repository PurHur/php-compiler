--TEST--
SPL RecursiveIteratorIterator extended methods — getDepth/setMaxDepth (#13135, ext/spl/spl_iterators.c)
--FILE--
<?php
$rii = new RecursiveIteratorIterator(new RecursiveArrayIterator([1, [2, 3]]));
$rii->rewind();
echo $rii->getDepth(), "\n";
$rii->setMaxDepth(0);
$rii->rewind();
$out = [];
foreach ($rii as $value) {
    $out[] = $value;
}
echo json_encode($out), "\n";
var_export($rii->getMaxDepth());
echo "\n";
--EXPECT--
0
[1]
0
