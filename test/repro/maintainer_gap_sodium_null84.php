<?php
/**
 * Repro for #24772 / #20196 — sodium_bin2hex/hex2bin(null) soft-null under PROFILE=8.4
 * (Zend emits E_DEPRECATED + coerces to ''; prior #20196 left hex2bin as TypeError).
 *
 * VM:  PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_sodium_null84.php
 * AOT: PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=1 php bin/compile.php -o /tmp/sodium84 test/repro/maintainer_gap_sodium_null84.php && /tmp/sodium84
 */
$deps = [];
set_error_handler(static function (int $n, string $m) use (&$deps): bool {
    if (E_DEPRECATED === $n) {
        $deps[] = $m;
    }

    return true;
});

foreach (['sodium_bin2hex', 'sodium_hex2bin'] as $fn) {
    $deps = [];
    try {
        $r = $fn(null);
        $depOk = isset($deps[0]) && false !== strpos($deps[0], $fn.'(): Passing null to parameter #1 ($string)');
        echo $fn, ' coerced:', var_export($r, true), ' dep=', $depOk ? '1' : '0', "\n";
    } catch (Throwable $e) {
        echo $fn, ' ', get_class($e), "\n";
        echo $e->getMessage(), "\n";
    }
}

$deps = [];
try {
    $r = sodium_hex2bin('61', null);
    $depOk = isset($deps[0]) && false !== strpos($deps[0], 'sodium_hex2bin(): Passing null to parameter #2 ($ignore)');
    echo 'ignore coerced:', var_export($r, true), ' dep=', $depOk ? '1' : '0', "\n";
} catch (Throwable $e) {
    echo 'ignore ', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
