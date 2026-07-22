<?php
/**
 * Repro for #21964 — wrong-arity builtins must throw ArgumentCountError (Zend zend_API.c).
 */
$cases = [
    static function () { strpos(); },
    static function () { implode(); },
    static function () { preg_match(); },
    static function () { json_encode(); },
    static function () { count(); },
    static function () { abs(); },
    static function () { defined(); },
    static function () { constant(); },
    static function () { gettype(); },
    static function () { $x = 1; settype($x); },
    static function () { uniqid('a', false, 'x'); },
];
foreach ($cases as $i => $fn) {
    try {
        $fn();
        echo "$i ran\n";
    } catch (Throwable $e) {
        echo $i, ' ', get_class($e), "\n";
    }
}
