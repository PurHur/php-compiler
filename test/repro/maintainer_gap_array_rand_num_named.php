<?php
declare(strict_types=1);

$ok = 1;
mt_srand(12345);
$named = array_rand([1, 2, 3], num: 2);
mt_srand(12345);
$pos = array_rand([1, 2, 3], 2);
if ($named != $pos) {
    $ok = 0;
}
// Zend reference for mt_srand(12345), pick 2 from [1,2,3]
$expected = [1, 2];
if ($named !== $expected) {
    $ok = 0;
}
echo $ok ? "ok\n" : "fail\n";
