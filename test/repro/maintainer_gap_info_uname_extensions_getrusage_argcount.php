<?php
foreach ([
    static function () {
        php_uname('s', 'x');
    },
    static function () {
        get_loaded_extensions(false, 1);
    },
    static function () {
        getrusage(0, 1);
    },
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
