<?php
/** Repro #25407 — wrong argc must raise ArgumentCountError (php-src-strict). */
$cases = [
    'str_replace' => static function () { str_replace('a', 'b'); },
    'substr_replace' => static function () { substr_replace('hello', 'X'); },
    'preg_replace' => static function () { preg_replace('/x/'); },
    'preg_filter' => static function () { preg_filter('/a/'); },
    'preg_replace_callback' => static function () { preg_replace_callback('/a/'); },
    'password_hash' => static function () { password_hash('x'); },
    'fgetcsv' => static function () { fgetcsv(); },
    'fputcsv' => static function () { fputcsv(); },
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo $name, " ran\n";
    } catch (ArgumentCountError $e) {
        echo $name, ' ArgumentCountError: ', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
