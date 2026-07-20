<?php

/**
 * #21234 — header()/preg_quote()/printf(null) DEP+coerce under PHP_COMPILER_PROFILE=8.4.
 *
 * VM:  PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_21234_header_preg_quote_printf_null_forward84.php
 * AOT: PHP_COMPILER_PROFILE=8.4 php bin/compile.php -o /tmp/i21234 test/repro/issue_21234_header_preg_quote_printf_null_forward84_aot.php && /tmp/i21234
 */
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if (E_DEPRECATED === $no) {
        ++$deps;
    }

    return true;
});
$cases = [
    ['header', static fn () => (header(null) || true) && 'ok'],
    ['preg_quote', static fn () => preg_quote(null)],
    ['printf', static fn () => printf(null)],
];
foreach ($cases as [$n, $fn]) {
    $prev = $deps;
    try {
        $r = $fn();
        if ($deps <= $prev) {
            echo $n, " missing_deprecation\n";
            exit(1);
        }
        echo $n, ' OK ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $n, ' ', get_class($e), "\n";
        exit(1);
    }
}
echo "OK\n";
