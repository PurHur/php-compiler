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
