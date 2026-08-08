<?php

declare(strict_types=1);

// #28795 — var_dump / debug_zval_dump circular *RECURSION* (php-src ext/standard/var.c)

$a = [];
$a[] = &$a;

ob_start();
var_dump($a);
$vd = ob_get_clean();
if (!str_contains($vd, '*RECURSION*')) {
    fwrite(STDERR, "var_dump missing *RECURSION*:\n".$vd);
    exit(1);
}
if (substr_count($vd, 'array(') > 1) {
    fwrite(STDERR, "var_dump nested past recursion marker:\n".$vd);
    exit(1);
}

ob_start();
debug_zval_dump($a);
$dz = ob_get_clean();
if (!str_contains($dz, '*RECURSION*')) {
    fwrite(STDERR, "debug_zval_dump missing *RECURSION*:\n".$dz);
    exit(1);
}

$o = new stdClass();
$o->self = $o;
ob_start();
var_dump($o);
$ov = ob_get_clean();
if (!str_contains($ov, '*RECURSION*')) {
    fwrite(STDERR, "object var_dump missing *RECURSION*:\n".$ov);
    exit(1);
}

// Shared (non-circular) array must still dump twice — not *RECURSION*.
$x = [1];
$pair = [$x, $x];
ob_start();
var_dump($pair);
$shared = ob_get_clean();
if (str_contains($shared, '*RECURSION*')) {
    fwrite(STDERR, "shared array falsely marked recursive:\n".$shared);
    exit(1);
}

echo "ok\n";
