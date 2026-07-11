--TEST--
Language: null coalesce ?? precedence — RHS +/concat bind tighter (Zend/zend_compile.c)
--FILE--
<?php
function ok(string $label, $got, $expected): void
{
    if ($got === $expected) {
        echo "ok {$label}\n";
        return;
    }
    echo "fail {$label}: got ", var_export($got, true), ", expected ", var_export($expected, true), "\n";
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
--EXPECT--
ok null ?? 1 + 2
ok unset coalesce add
ok dim coalesce add
ok null ?? concat
ok nullsafe coalesce add
ok chained coalesce add
