--TEST--
calendar jewishtojd/jdtojewish/jdtofrench/frenchtojd/juliantojd (#11875)
--FILE--
<?php
declare(strict_types=1);
$jd = jewishtojd(1, 1, 5781);
echo $jd, "\n";
echo jdtojewish($jd), "\n";
$fj = frenchtojd(10, 20, 13);
echo jdtofrench($fj), "\n";
echo juliantojd(1, 1, 2024), "\n";
?>
--EXPECT--
2459112
1/1/5781
10/20/13
2460324
