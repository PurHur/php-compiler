<?php

declare(strict_types=1);

/**
 * #34290 — mb_convert_case(MB_CASE_TITLE) Cyrillic/Greek runtime under thin AOT
 * (NestedJIT-safe maps beyond Latin-1; leftover of #34284 / #34288).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_case)
 */
$ue = 'über city';
echo 'ue=', mb_convert_case($ue, MB_CASE_TITLE), "\n";
$cyr = 'привет мир';
echo 'cyr=', mb_convert_case($cyr, MB_CASE_TITLE), "\n";
$el = 'αθήνα';
echo 'el=', mb_convert_case($el, MB_CASE_TITLE), "\n";
