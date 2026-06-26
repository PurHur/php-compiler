<?php
/**
 * Issue #12025 — function static array-offset constant initializer (Zend parity).
 *
 * Zend: ok
 * VM (before fix): compile fatal TypeError in functionStaticInitReferencesLocal
 */
function f_list(): int
{
    static $x = [1, 2][0];

    return $x;
}

function f_assoc(): int
{
    static $x = ['a' => 1]['a'];

    return $x;
}

function f_binary(): int
{
    static $x = 1 + 2;

    return $x;
}

if (f_list() !== 1 || f_assoc() !== 1 || f_binary() !== 3) {
    fwrite(STDERR, "fail\n");
    exit(1);
}
echo "ok\n";
