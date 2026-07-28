<?php
// Repro #24050 — MB_CASE_FOLD / MB_CASE_*_SIMPLE (php-src ext/mbstring/mbstring.c)
foreach ([
    'MB_CASE_UPPER', 'MB_CASE_LOWER', 'MB_CASE_TITLE', 'MB_CASE_FOLD',
    'MB_CASE_UPPER_SIMPLE', 'MB_CASE_LOWER_SIMPLE', 'MB_CASE_TITLE_SIMPLE', 'MB_CASE_FOLD_SIMPLE',
] as $c) {
    echo $c, '=', defined($c) ? (string) constant($c) : 'UNDEF', "\n";
}
try {
    echo mb_convert_case('Straße', defined('MB_CASE_FOLD') ? MB_CASE_FOLD : 3, 'UTF-8'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
