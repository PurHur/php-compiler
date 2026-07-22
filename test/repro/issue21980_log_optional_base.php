<?php
/**
 * Repro for #21980 — log() optional $base (php-src ext/standard/math.c).
 *
 * VM:  php bin/vm.php test/repro/issue21980_log_optional_base.php
 * AOT: php bin/compile.php -o /tmp/issue21980_log test/repro/issue21980_log_optional_base.php && /tmp/issue21980_log
 * Zend: php test/repro/issue21980_log_optional_base.php
 */
$cases = [
    'log_100_10' => static function () { return log(100, 10); },
    'log_8_2' => static function () { return log(8, 2); },
    'log_e' => static function () { return log(M_E); },
    'log_10_1' => static function () { return log(10, 1); },
];
foreach ($cases as $name => $fn) {
    $v = $fn();
    if (is_nan($v)) {
        echo $name, "=NAN\n";
    } else {
        echo $name, '=', $v, "\n";
    }
}
try {
    log();
    echo "log_zero ran\n";
} catch (Throwable $e) {
    echo 'log_zero ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    log(1, 2, 3);
    echo "log_three ran\n";
} catch (Throwable $e) {
    echo 'log_three ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    log(10, 0);
    echo "log_base0 ran\n";
} catch (Throwable $e) {
    echo 'log_base0 ', get_class($e), ': ', $e->getMessage(), "\n";
}
