<?php
// php-src ext/standard/string.c — TypeError "given" uses class name (#11227).
class C {}

$checks = [
    ['strlen', [new C()]],
    ['substr', [new C(), 0]],
    ['strpos', [new C(), 'x']],
];

foreach ($checks as [$fn, $args]) {
    try {
        $fn(...$args);
        echo "$fn: NO_THROW\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
