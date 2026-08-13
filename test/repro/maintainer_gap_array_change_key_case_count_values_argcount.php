<?php
foreach ([
    static function () {
        array_change_key_case(['A' => 1], CASE_LOWER, 'x');
    },
    static function () {
        array_change_key_case();
    },
    static function () {
        array_count_values([1], 'x');
    },
    static function () {
        array_count_values();
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
