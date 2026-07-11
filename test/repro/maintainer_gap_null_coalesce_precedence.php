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

ok('null ?? 1 + 2', null ?? 1 + 2, 3);

$a = null;
ok('unset coalesce add', $a ?? 1 + 2, 3);

$x = [];
ok('dim coalesce add', $x['missing'] ?? 1 + 2, 3);

ok('null ?? concat', null ?? 'x' . 'y', 'xy');

$o = null;
ok('nullsafe coalesce add', $o?->p ?? 1 + 2, 3);

ok('chained coalesce add', null ?? null ?? 1 + 2, 3);
