<?php
// #18993 — 8.4 forward profile must TypeError on null encoding operands (ext/iconv/iconv.c).
// Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_iconv_null_forward84.php

$checks = [
    'from' => static fn () => iconv(null, 'UTF-8', 'x'),
    'to' => static fn () => iconv('UTF-8', null, 'x'),
];
foreach ($checks as $label => $factory) {
    try {
        $factory();
        echo $label, ": uncaught\n";
        exit(1);
    } catch (TypeError $e) {
        echo $label, ': TypeError:', $e->getMessage(), "\n";
    }
}
echo "ok\n";
