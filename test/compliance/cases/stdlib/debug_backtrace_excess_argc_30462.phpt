--TEST--
debug_backtrace/debug_print_backtrace excess argc → ArgumentCountError (#30462)
--FILE--
<?php
foreach (['debug_backtrace', 'debug_print_backtrace'] as $fn) {
    try {
        $fn(0, 0, 0);
        echo $fn, ": NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
debug_backtrace() expects at most 2 arguments, 3 given
debug_print_backtrace() expects at most 2 arguments, 3 given
