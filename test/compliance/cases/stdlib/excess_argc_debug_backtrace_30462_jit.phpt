--TEST--
debug_backtrace/debug_print_backtrace excess argc JIT → ArgumentCountError (#30462)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$cases = [
    static fn () => debug_backtrace(0, 0, 1),
    static fn () => debug_print_backtrace(0, 0, 1),
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
debug_backtrace() expects at most 2 arguments, 3 given
debug_print_backtrace() expects at most 2 arguments, 3 given
