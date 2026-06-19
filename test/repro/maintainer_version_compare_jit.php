<?php
$cases = [
    ['1.0.0', '1.0.1', '<', true],
    ['1.0.0', '1.0.0', '=', true],
    ['1.0.0-dev', '1.0.0', '<', true],
    ['8.2.0', '8.10.0', '<', true],
];
foreach ($cases as [$a, $b, $op, $want]) {
    $got = version_compare($a, $b, $op);
    echo "$a $op $b => ", var_export($got, true), $got === $want ? " OK\n" : " FAIL\n";
}
echo version_compare('1.2', '1.10'), "\n";
