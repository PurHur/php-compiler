--TEST--
stdlib log() optional $base + arity/ValueError (#21980, ext/standard/math.c)
--FILE--
<?php
echo (log(100, 10) === 2.0) ? "log_100_10_ok\n" : "log_100_10_bad\n";
echo (log(8, 2) === 3.0) ? "log_8_2_ok\n" : "log_8_2_bad\n";
echo (abs(log(M_E) - 1.0) < 1e-12) ? "log_e_ok\n" : "log_e_bad\n";
echo is_nan(log(10, 1)) ? "log_base1_nan\n" : "log_base1_bad\n";
try {
    log();
    echo "log_zero ran\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    log(1, 2, 3);
    echo "log_three ran\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    log(10, 0);
    echo "log_base0 ran\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
log_100_10_ok
log_8_2_ok
log_e_ok
log_base1_nan
ArgumentCountError: log() expects at least 1 argument, 0 given
ArgumentCountError: log() expects at most 2 arguments, 3 given
ValueError: log(): Argument #2 ($base) must be greater than 0
