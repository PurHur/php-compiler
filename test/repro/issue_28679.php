<?php
/**
 * #28679 — str_increment/str_decrement excess argc → ArgumentCountError (Zend), not LogicException.
 *
 * php-src: ext/standard/string.c / basic_functions.stub.php
 * Requires PHP_COMPILER_PROFILE≥8.3 (supportsStrIncrement).
 */
error_reporting(E_ALL);
$cases = [
    'str_increment_excess' => static function () {
        str_increment('a', 'x');
    },
    'str_increment_zero' => static function () {
        str_increment();
    },
    'str_decrement_excess' => static function () {
        str_decrement('b', 'x');
    },
    'str_decrement_zero' => static function () {
        str_decrement();
    },
    'str_increment_ok' => static function () {
        return str_increment('a');
    },
    'str_decrement_ok' => static function () {
        return str_decrement('b');
    },
];

foreach ($cases as $name => $fn) {
    try {
        $r = $fn();
        echo $name, ':OK:', (string) $r, "\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
