<?php
// #18837 — Z_PARAM_STR builtins must TypeError on null under 8.4 forward profile.
// Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_string_builtin_null_coerce_batch2.php

$checks = [
    'nl2br(null)' => static fn () => nl2br(null),
    'str_shuffle(null)' => static fn () => str_shuffle(null),
    'str_rot13(null)' => static fn () => str_rot13(null),
    'crc32(null)' => static fn () => crc32(null),
    'soundex(null)' => static fn () => soundex(null),
    'metaphone(null)' => static fn () => metaphone(null),
    'convert_uuencode(null)' => static fn () => convert_uuencode(null),
    'bin2hex(null)' => static fn () => bin2hex(null),
    'hebrev(null)' => static fn () => hebrev(null),
    'quoted_printable_encode(null)' => static fn () => quoted_printable_encode(null),
];
foreach ($checks as $label => $factory) {
    try {
        $result = is_callable($factory) ? $factory() : $factory;
        echo $label, ' => ';
        var_export($result);
        echo "\n";
    } catch (TypeError $e) {
        echo $label, ' => TypeError:', $e->getMessage(), "\n";
    }
}
