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
