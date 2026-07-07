<?php

declare(strict_types=1);

/**
 * Issue #17220 — var_export() __set_state / (object) array header spacing (ext/standard/var.c).
 *
 * php-src uses compact "array(" for __set_state and (object), not "array (".
 */
class D
{
    private int $secret = 99;

    protected int $prot = 1;

    public int $pub = 2;
}

$out = var_export(new D(), true);
if (!str_contains($out, '__set_state(array(')) {
    fwrite(STDERR, "expected compact __set_state(array( header, got:\n".$out."\n");
    exit(1);
}
if (str_contains($out, '__set_state(array (')) {
    fwrite(STDERR, "unexpected space after array keyword in __set_state header\n");
    exit(1);
}

$list = [1, 2, 3];
settype($list, 'object');
$std = var_export($list, true);
if (!str_contains($std, '(object) array(')) {
    fwrite(STDERR, "expected compact (object) array( header, got:\n".$std."\n");
    exit(1);
}

echo "ok\n";
