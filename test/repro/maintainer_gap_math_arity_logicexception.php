<?php
/**
 * Repro for #21982 — math/base_convert under-arity must throw ArgumentCountError
 * (php-src zend_API.c / ext/standard/math.c), not LogicException.
 */
$cases = [
    'atan2' => static function () { atan2(1); },
    'pow' => static function () { pow(2); },
    'fmod' => static function () { fmod(5); },
    'intdiv' => static function () { intdiv(5); },
    'base_convert' => static function () { base_convert('10', 2); },
    'log' => static function () { log(); },
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo $name, " ran\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
