--TEST--
Language: elvis ?: precedence — RHS +/concat bind tighter (Zend/zend_language_parser.y)
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

ok('null ?: 1 + 2', null ?: 1 + 2, 3);
ok('0 ?: 1 + 2', 0 ?: 1 + 2, 3);
ok('false ?: 1 + 2', false ?: 1 + 2, 3);
ok('empty string ?: concat', '' ?: 'a' . 'b', 'ab');

$a = 0;
ok('var 0 ?: 1 + 2', $a ?: 1 + 2, 3);
--EXPECT--
ok null ?: 1 + 2
ok 0 ?: 1 + 2
ok false ?: 1 + 2
ok empty string ?: concat
ok var 0 ?: 1 + 2
