<?php

declare(strict_types=1);

$fail = 0;

$expected1 = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
$got1 = range(1, 10, -1);
if ($got1 !== $expected1) {
    fwrite(STDERR, 'fail: range(1,10,-1) got '.var_export($got1, true)."\n");
    $fail = 1;
}

$expected2 = [10, 9, 8, 7, 6, 5, 4, 3, 2, 1];
$got2 = range(10, 1, 1);
if ($got2 !== $expected2) {
    fwrite(STDERR, 'fail: range(10,1,1) got '.var_export($got2, true)."\n");
    $fail = 1;
}

exit($fail);
