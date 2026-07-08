<?php

declare(strict_types=1);

/**
 * Issue #17444 — var_dump()/print_r() must not leak internal anonymous class NUL+filename suffix.
 */

$o = new class {};

ob_start();
var_dump($o);
$vd = ob_get_clean();
if (!preg_match('/object\(class@anonymous\)#\d+ \(0\) \{/', $vd)) {
    echo "var_dump fail: {$vd}\n";
    exit(1);
}
if (str_contains($vd, "\0")) {
    echo "var_dump NUL leak\n";
    exit(1);
}

ob_start();
print_r($o);
$pr = ob_get_clean();
if (!str_starts_with($pr, "class@anonymous Object\n")) {
    echo "print_r fail: {$pr}\n";
    exit(1);
}
if (str_contains($pr, "\0")) {
    echo "print_r NUL leak\n";
    exit(1);
}

echo "ok\n";
