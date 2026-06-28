--TEST--
Language: simple assign with concat RHS — full chain, no spurious undefined-variable warning (#13106)
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

$path = 'a' . 'b';
ok('literal concat assign', $path, 'ab');

$tmpdir = sys_get_temp_dir();
$path = $tmpdir . '/x';
ok('func concat assign', $path, $tmpdir . '/x');

$pid = getmypid();
$path = $pid . '.txt';
ok('pid concat assign', $path, $pid . '.txt');

$x = 1 . 2;
ok('int concat assign', $x, '12');
--EXPECT--
ok literal concat assign
ok func concat assign
ok pid concat assign
ok int concat assign
