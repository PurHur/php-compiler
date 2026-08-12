--TEST--
debug_backtrace/debug_print_backtrace excess argc JIT → ArgumentCountError (#30462)
--FILE--
<?php
declare(strict_types=1);

foreach (['debug_backtrace', 'debug_print_backtrace'] as $fn) {
    try {
        $fn(0, 0, 0);
        echo $fn, ": NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
ArgumentCountError: debug_backtrace() expects at most 2 arguments, 3 given
ArgumentCountError: debug_print_backtrace() expects at most 2 arguments, 3 given
