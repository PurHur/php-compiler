<?php

/**
 * Repro #21517 — openssl_digest(null)/sodium_bin2hex(null) soft-null under PROFILE=8.4
 * (reverts wrong-direction #20207/#20196 TypeError; Zend emits E_DEPRECATED + coerces).
 *
 * VM:  PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_21517_openssl_digest_sodium_bin2hex_null_soft84.php
 * AOT: PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=1 php bin/compile.php -o /tmp/i21517 test/repro/issue_21517_openssl_digest_sodium_bin2hex_null_soft84.php && /tmp/i21517
 */

$deps = [];
set_error_handler(static function (int $n, string $m) use (&$deps): bool {
    if (E_DEPRECATED === $n) {
        $deps[] = $m;
        echo "DEP\n";
    }

    return true;
});

$pass = 0;
$fail = 0;

try {
    $empty = openssl_digest('', 'sha256');
    $null = openssl_digest(null, 'sha256');
    $depOk = isset($deps[0]) && false !== strpos($deps[0], 'openssl_digest(): Passing null to parameter #1 ($data)');
    if (is_string($null) && $empty === $null && $depOk) {
        echo "openssl_digest OK ", $null, "\n";
        ++$pass;
    } else {
        echo 'openssl_digest FAIL ', var_export($null, true), ' dep=', var_export($deps, true), "\n";
        ++$fail;
    }
} catch (Throwable $e) {
    echo 'openssl_digest ', get_class($e), "\n";
    ++$fail;
}

$deps = [];
try {
    $r = sodium_bin2hex(null);
    $depOk = isset($deps[0]) && false !== strpos($deps[0], 'sodium_bin2hex(): Passing null to parameter #1 ($string)');
    if ('' === $r && $depOk) {
        echo "sodium_bin2hex OK ''\n";
        ++$pass;
    } else {
        echo 'sodium_bin2hex FAIL ', var_export($r, true), ' dep=', var_export($deps, true), "\n";
        ++$fail;
    }
} catch (Throwable $e) {
    echo 'sodium_bin2hex ', get_class($e), "\n";
    ++$fail;
}

echo "$pass passed, $fail failed\n";
if ($fail > 0) {
    exit(1);
}
