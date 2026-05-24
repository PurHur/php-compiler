<?php declare(strict_types=1);
$a = [1, 2, 3];
foreach ($a as &$v) {
    $v *= 2;
}
echo (string) $a[0], (string) $a[1], (string) $a[2], "\n";
