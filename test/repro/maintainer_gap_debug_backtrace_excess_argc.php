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
