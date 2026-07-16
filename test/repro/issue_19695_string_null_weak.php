<?php
/**
 * Repro for #19695 — weak-mode string rejects null (php-src-strict).
 * Expect: TypeError with Zend message shape; int/float/bool still coerce.
 */
function stripCallSite(string $msg): string
{
    $pos = strpos($msg, ', called in ');
    return false === $pos ? $msg : substr($msg, 0, $pos);
}

function f(string $s): string
{
    return $s;
}

try {
    var_export(f(null));
    echo " FAIL_NO_THROW\n";
    exit(1);
} catch (TypeError $e) {
    $msg = stripCallSite($e->getMessage());
    if (!str_contains($msg, 'must be of type string, null given')) {
        echo "FAIL_MSG: {$msg}\n";
        exit(1);
    }
    echo "param_ok: {$msg}\n";
}

if (f(1) !== '1' || f(true) !== '1' || f(1.5) !== '1.5') {
    echo "FAIL_COERCE\n";
    exit(1);
}
echo "coerce_ok\n";
echo "PASS\n";
