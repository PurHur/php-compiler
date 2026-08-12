--TEST--
ob_clean() excess argc → ArgumentCountError (#30525)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    static fn () => ob_clean('x'),
    static fn () => ob_clean(1, 2),
] as $fn) {
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
ob_clean() expects exactly 0 arguments, 1 given
ob_clean() expects exactly 0 arguments, 2 given
