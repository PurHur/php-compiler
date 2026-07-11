<?php
declare(strict_types=1);

$dt = new DateTime('2020-01-01');
$dt->modify('+1 day');

ob_start();
var_export([true, $dt->format('Y-m-d')]);
$inline = ob_get_clean();
if (!str_contains($inline, 'array')) {
    fwrite(STDERR, "fail: inline var_export missing array, got: {$inline}\n");
    exit(1);
}
if (!str_contains($inline, '2020-01-02')) {
    fwrite(STDERR, "fail: inline var_export missing date, got: {$inline}\n");
    exit(1);
}

$a = [true, $dt->format('Y-m-d')];
$ok = ($a === [true, '2020-01-02']);
if (!$ok) {
    fwrite(STDERR, "fail: assigned array mismatch\n");
    exit(1);
}

echo "inline_var_export: ok\n";
