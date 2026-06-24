<?php
// php-src ext/standard/string.c — null string operands coerce when caller non-strict (#11322).
$funcs = [
    'nl2br' => [null],
    'chop' => [null],
    'rtrim' => [null],
    'ltrim' => [null],
    'trim' => [null],
    'wordwrap' => [null],
    'ucfirst' => [null],
    'lcfirst' => [null],
    'ucwords' => [null],
];
foreach ($funcs as $fn => $args) {
    try {
        $fn(...$args);
        echo "$fn: NO_THROW\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
// Valid operands unchanged
echo trim('  x  '), "\n";
echo nl2br("a\nb"), "\n";
