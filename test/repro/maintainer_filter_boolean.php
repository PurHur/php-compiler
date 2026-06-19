<?php

declare(strict_types=1);

$cases = [
    ['true', true],
    ['false', false],
    ['on', true],
    ['off', false],
    ['yes', true],
    ['no', false],
    ['1', true],
    ['0', false],
    ['maybe', false],
];
foreach ($cases as [$in, $want]) {
    $got = filter_var($in, FILTER_VALIDATE_BOOLEAN);
    echo "$in => ", var_export($got, true), $got === $want ? " OK\n" : " FAIL\n";
}
