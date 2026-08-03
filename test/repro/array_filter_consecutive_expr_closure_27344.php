<?php
declare(strict_types=1);

// Issue #27344 — second expression-position array_filter(..., Closure) must keep CV haystack.
$a = [1, 2, 3];
$b = [1, 2, 3];
ob_start();
var_dump(array_filter($a, static fn ($v): bool => $v > 5));
var_dump(array_filter($b, static fn ($v): bool => $v > 1));
$out = ob_get_clean();
if (!str_contains($out, "array(0)") || !str_contains($out, "int(2)") || !str_contains($out, "int(3)")) {
    fwrite(STDERR, "fail consecutive expr closure:\n" . $out);
    exit(1);
}
echo "ok\n";
