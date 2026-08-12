<?php
foreach ([
    static fn () => ob_start('trim', 0, 0, 'extra'),
    static fn () => ob_start('trim', 0, 0, 'extra', 'more'),
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
