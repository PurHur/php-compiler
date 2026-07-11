--TEST--
Language: simple assign with bitwise RHS — no spurious undefined-variable warning (#13114)
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

$x = 1 | 2;
ok('or', $x, 3);

$x = 1 ^ 2;
ok('xor', $x, 3);

$x = 1 & 2;
ok('and', $x, 0);

$x = 1 << 2;
ok('shift left', $x, 4);

$x = 8 >> 1;
ok('shift right', $x, 4);
--EXPECT--
ok or
ok xor
ok and
ok shift left
ok shift right
