--TEST--
array_change_key_case/array_count_values excess argc JIT → ArgumentCountError (#30536)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
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
?>
--EXPECT--
array_change_key_case() expects at most 2 arguments, 3 given
array_change_key_case() expects at least 1 argument, 0 given
array_count_values() expects exactly 1 argument, 2 given
array_count_values() expects exactly 1 argument, 0 given
