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
