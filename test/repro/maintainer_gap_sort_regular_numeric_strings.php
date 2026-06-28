<?php

declare(strict_types=1);

// Repro for #13028 — sort(SORT_REGULAR) on numeric strings (php-src zend_compare).
$a = ['10', '2', '1'];
sort($a, SORT_REGULAR);
$expected = ['1', '2', '10'];
if ($a !== $expected) {
    echo 'fail: sort SORT_REGULAR numeric strings: got ';
    var_export($a);
    echo ' expected ';
    var_export($expected);
    echo "\n";
    exit(1);
}

$b = ['10', 2, '1'];
sort($b, SORT_REGULAR);
$expectedMixed = ['1', 2, '10'];
if ($b !== $expectedMixed) {
    echo 'fail: sort SORT_REGULAR mixed int/string: got ';
    var_export($b);
    echo ' expected ';
    var_export($expectedMixed);
    echo "\n";
    exit(1);
}

$c = ['banana', 'apple', 'cherry'];
sort($c, SORT_REGULAR);
$expectedLex = ['apple', 'banana', 'cherry'];
if ($c !== $expectedLex) {
    echo 'fail: sort SORT_REGULAR lexical strings: got ';
    var_export($c);
    echo ' expected ';
    var_export($expectedLex);
    echo "\n";
    exit(1);
}

echo "ok\n";
