<?php
/**
 * #28682 — array_first/array_last excess argc → ArgumentCountError (Zend), not LogicException.
 *
 * php-src: ext/standard/array.c / array.stub.php
 * Requires PHP_COMPILER_PROFILE≥8.5 (supportsPhp85ArrayFirstLast).
 */
error_reporting(E_ALL);
$cases = [
    'array_first_excess' => static function () {
        array_first([1], 2);
    },
    'array_first_zero' => static function () {
        array_first();
    },
    'array_last_excess' => static function () {
        array_last([1], 2);
    },
    'array_last_zero' => static function () {
        array_last();
    },
    'array_first_ok' => static function () {
        return array_first([10, 20]);
    },
    'array_last_ok' => static function () {
        return array_last([10, 20]);
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
