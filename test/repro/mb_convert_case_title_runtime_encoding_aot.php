<?php

declare(strict_types=1);

/**
 * #35151 — mb_convert_case(TITLE) with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_case)
 */
$enc = 'UTF-8';
echo 'title_enc=', mb_convert_case('istanbul', MB_CASE_TITLE, $enc), "\n";
echo 'title_simple_enc=', mb_convert_case('istanbul', MB_CASE_TITLE_SIMPLE, $enc), "\n";
$ue = 'ü';
$enc2 = 'UTF-8';
echo 'title_ue=', mb_convert_case($ue.' city', MB_CASE_TITLE, $enc2), "\n";
try {
    $bad = 'nope';
    echo mb_convert_case('ab', MB_CASE_TITLE, $bad);
} catch (ValueError $e) {
    echo 'bad_enc=', $e->getMessage(), "\n";
}
