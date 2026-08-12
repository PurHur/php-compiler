<?php
foreach ([
    static function () {
        $a = [1];
        shuffle($a, 'x');
    },
    static function () {
        $a = ['a'];
        natsort($a, 'x');
    },
    static function () {
        $a = ['a'];
        natcasesort($a, 'x');
    },
    static function () {
        shuffle();
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
