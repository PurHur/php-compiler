<?php
/**
 * Repro for #3312 — key() on object must return mangled private/protected names (php-src array.c).
 */
declare(strict_types=1);

class MaintainerArrayPointerObject {
    private int $a = 1;
    public int $x = 2;
}

$o = new MaintainerArrayPointerObject();
reset($o);
$privateKey = key($o);
$expectedPrivate = "\0".'MaintainerArrayPointerObject'."\0".'a';
if ($privateKey !== $expectedPrivate) {
    echo "private key mismatch: got ", var_export($privateKey, true), "\n";
    exit(1);
}
next($o);
if ('x' !== key($o)) {
    echo "public key mismatch\n";
    exit(1);
}
echo "ok\n";
