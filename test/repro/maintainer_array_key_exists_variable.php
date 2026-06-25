<?php

declare(strict_types=1);

$array = ['a' => 1, 'b' => 2];
if (!array_key_exists('a', $array) || array_key_exists('c', $array)) {
    echo "FAIL\n";
    exit(1);
}
if (!key_exists(0, [10, 20])) {
    echo "FAIL key_exists\n";
    exit(1);
}
echo "ok\n";
