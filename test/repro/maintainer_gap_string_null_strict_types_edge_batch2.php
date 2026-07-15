<?php
// #19117 — Z_PARAM_STR builtins must TypeError on null when caller is declare(strict_types=1).
declare(strict_types=1);

$checks = [
    'hebrev(null)' => static fn () => hebrev(null),
    'quotemeta(null)' => static fn () => quotemeta(null),
    'str_shuffle(null)' => static fn () => str_shuffle(null),
    'ucfirst(null)' => static fn () => ucfirst(null),
    'lcfirst(null)' => static fn () => lcfirst(null),
    'ucwords(null)' => static fn () => ucwords(null),
    'convert_uuencode(null)' => static fn () => convert_uuencode(null),
    'soundex(null)' => static fn () => soundex(null),
    'metaphone(null)' => static fn () => metaphone(null),
];

$failures = 0;
foreach ($checks as $label => $factory) {
    try {
        $factory();
        echo "$label: FAIL:no_error\n";
        ++$failures;
    } catch (TypeError $e) {
        echo "$label: strict_edge_ok\n";
    }
}

exit($failures > 0 ? 1 : 0);
