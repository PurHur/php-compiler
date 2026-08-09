--TEST--
stdlib round() null $mode JIT — DEP then ValueError on PHP 8.4 (#29384)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    echo 'WARN:', $str, "\n";

    return true;
});
try {
    round(1.5, 0, null);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
WARN:round(): Passing null to parameter #3 ($mode) of type RoundingMode|int is deprecated
ValueError:round(): Argument #3 ($mode) must be a valid rounding mode (RoundingMode::*)
--EXPECT_EXIT--
0
