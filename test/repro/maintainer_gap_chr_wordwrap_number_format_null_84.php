<?php
// #18850 — Z_PARAM_LONG / Z_PARAM_STR / float builtins must TypeError on null under 8.4 forward profile.
// Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_chr_wordwrap_number_format_null_84.php

$checks = [
    'chr(null)' => static fn () => chr(null),
    'wordwrap(null)' => static fn () => wordwrap(null),
    'number_format(null)' => static fn () => number_format(null),
    'dechex(null)' => static fn () => dechex(null),
    'decbin(null)' => static fn () => decbin(null),
    'decoct(null)' => static fn () => decoct(null),
    'str_pad(null, 5)' => static fn () => str_pad(null, 5),
];
foreach ($checks as $label => $factory) {
    try {
        $factory();
        echo $label, ' => uncaught', "\n";
    } catch (TypeError $e) {
        echo $label, ' => TypeError:', $e->getMessage(), "\n";
    }
}
