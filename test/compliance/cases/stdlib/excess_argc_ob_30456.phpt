--TEST--
ob_get_* / ob_end_flush excess argc → ArgumentCountError (#30456)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$cases = [
    static fn () => ob_get_level(1),
    static fn () => ob_get_clean(1, 2),
    static fn () => ob_get_flush(1, 2),
    static fn () => ob_end_flush(1),
];
foreach ($cases as $fn) {
    try {
        $fn();
        echo "NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
ob_get_level() expects exactly 0 arguments, 1 given
ob_get_clean() expects exactly 0 arguments, 2 given
ob_get_flush() expects exactly 0 arguments, 2 given
ob_end_flush() expects exactly 0 arguments, 1 given
