<?php
declare(strict_types=1);

$failed = 0;
foreach ([
    'str_word_count' => static fn () => str_word_count(null),
    'hex2bin' => static fn () => hex2bin(null),
    'chunk_split' => static fn () => chunk_split(null),
    'str_split' => static fn () => str_split(null),
    'strrev' => static fn () => strrev(null),
    'convert_uudecode' => static fn () => convert_uudecode(null),
    'timezone_name_from_abbr' => static fn () => timezone_name_from_abbr(null),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: FAIL expected TypeError\n";
        ++$failed;
    } catch (TypeError $e) {
        echo "$label: ok\n";
    }
}
exit($failed > 0 ? 1 : 0);
