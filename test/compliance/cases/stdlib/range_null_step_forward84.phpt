--TEST--
range() null $step DEP then ValueError under PROFILE=8.4 (#29352)
--ENV--
PHP_COMPILER_PROFILE=8.4 forward
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
try {
    range(0, 2, null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ERR[8192]: range(): Passing null to parameter #3 ($step) of type int|float is deprecated
ValueError
range(): Argument #3 ($step) cannot be 0
