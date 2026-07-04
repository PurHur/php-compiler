<?php

declare(strict_types=1);

$expectedNotice = 'Only variables should be passed by reference';

ob_start();
array_walk((object) ['x' => 1], static fn ($v) => print($v));
$inlineOut = ob_get_clean();
$inlineNotice = error_get_last();
error_clear_last();

ob_start();
$a = (object) ['x' => 1];
array_walk($a, static fn ($v) => print($v));
$varOut = ob_get_clean();
$varNotice = error_get_last();
error_clear_last();

ob_start();
array_walk_recursive((object) ['x' => 1], static fn ($v) => print($v));
$recursiveOut = ob_get_clean();
$recursiveNotice = error_get_last();
error_clear_last();

if ('1' !== $inlineOut) {
    echo "fail: inline output [$inlineOut]\n";
    exit(1);
}
if (null !== $inlineNotice && str_contains($inlineNotice['message'], $expectedNotice)) {
    echo "fail: inline array_walk emitted by-ref notice\n";
    exit(1);
}
if ('1' !== $varOut) {
    echo "fail: variable output [$varOut]\n";
    exit(1);
}
if (null !== $varNotice && str_contains($varNotice['message'], $expectedNotice)) {
    echo "fail: variable array_walk emitted by-ref notice\n";
    exit(1);
}
if ('1' !== $recursiveOut) {
    echo "fail: recursive output [$recursiveOut]\n";
    exit(1);
}
if (null !== $recursiveNotice && str_contains($recursiveNotice['message'], $expectedNotice)) {
    echo "fail: array_walk_recursive emitted by-ref notice\n";
    exit(1);
}

echo "ok\n";
