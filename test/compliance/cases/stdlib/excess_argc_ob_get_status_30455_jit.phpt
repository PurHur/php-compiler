--TEST--
ob_get_status() excess argc JIT → ArgumentCountError (#30455)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach ([
    static fn () => ob_get_status(true, true),
    static fn () => ob_get_status(true, true, true),
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
ob_get_status() expects at most 1 argument, 2 given
ob_get_status() expects at most 1 argument, 3 given
