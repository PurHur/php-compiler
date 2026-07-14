<?php
// #18914 — 8.4 forward profile must TypeError on null subject (ext/standard/string.c, ext/pcre/php_pcre.c).
// Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_str_replace_null_forward84.php

$checks = [
    'str_replace' => static fn () => str_replace('a', 'b', null),
    'str_ireplace' => static fn () => str_ireplace('a', 'b', null),
    'preg_replace' => static fn () => preg_replace('//', 'x', null),
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
