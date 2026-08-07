--TEST--
AOT: SplFixedArray foreach via __spl_ht (#28640)
--FILE--
<?php
$a = new SplFixedArray(3);
$a[0] = 10;
$a[1] = 20;
$a[2] = 30;
$sum = 0;
foreach ($a as $v) {
    $sum += $v;
}
echo $sum, "\n";
// Null pads remain iterable (Zend FE_FETCH_R); only UNDEFINED holes are skipped.
$b = new SplFixedArray(3);
$b[0] = 1;
$b[2] = 3;
foreach ($b as $v) {
    if (null === $v) {
        echo 'n,';
    } else {
        echo $v, ',';
    }
}
echo "\n";
--EXPECT--
60
1,n,3,
