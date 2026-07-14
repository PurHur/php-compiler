<?php
// #18822 — Z_PARAM_STR builtins coerce null on 8.4 forward profile.
// Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_string_builtin_null_coerce_batch2.php

$checks = [
    'nl2br(null)' => nl2br(null),
    'str_shuffle(null)' => str_shuffle(null),
    'str_rot13(null)' => str_rot13(null),
    'crc32(null)' => crc32(null),
    'soundex(null)' => soundex(null),
    'metaphone(null)' => metaphone(null),
    'convert_uuencode(null)' => convert_uuencode(null),
    'bin2hex(null)' => bin2hex(null),
    'hebrev(null)' => hebrev(null),
    'quoted_printable_encode(null)' => quoted_printable_encode(null),
];
foreach ($checks as $label => $result) {
    echo $label, ' => ';
    var_export($result);
    echo "\n";
}
