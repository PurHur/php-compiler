<?php

declare(strict_types=1);

// Issue #11908 — rand() must be registered and match Zend MT19937 sequences.
if (!function_exists('rand')) {
    echo "fail: rand() not registered (Zend 8.2 has rand)\n";
    exit(1);
}

mt_srand(12345);
$first = rand();
$second = rand(1, 100);
$expectedFirst = 1996335345;
$expectedSecond = 82;

if ($first !== $expectedFirst || $second !== $expectedSecond) {
    echo "fail: seeded rand() mismatch got {$first} {$second}, want {$expectedFirst} {$expectedSecond}\n";
    exit(1);
}

if (getrandmax() !== 2147483647) {
    echo 'fail: getrandmax()='.getrandmax()." want 2147483647\n";
    exit(1);
}

echo "ok\n";
