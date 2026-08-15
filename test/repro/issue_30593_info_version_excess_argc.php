<?php

foreach ([
    static function () {
        extension_loaded('standard', 'x');
    },
    static function () {
        phpinfo(INFO_GENERAL, 'x');
    },
    static function () {
        version_compare('1', '2', '<', 'x');
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
