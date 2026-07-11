<?php
function ok(string $label, $got, $expected): void
{
    if ($got === $expected) {
        echo "ok {$label}\n";
        return;
    }
    $exp = var_export($expected, true);
    $g = var_export($got, true);
    echo "fail {$label}: got {$g}, expected {$exp}\n";
}

ok('null ?: 1 + 2', null ?: 1 + 2, 3);
ok('0 ?: 1 + 2', 0 ?: 1 + 2, 3);
ok('false ?: 1 + 2', false ?: 1 + 2, 3);
ok('empty string ?: concat', '' ?: 'a' . 'b', 'ab');

$a = 0;
ok('var 0 ?: 1 + 2', $a ?: 1 + 2, 3);
