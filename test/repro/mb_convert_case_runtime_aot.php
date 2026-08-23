<?php

declare(strict_types=1);

/**
 * #34280 — mb_convert_case() runtime string under thin AOT (Unicode UPPER/LOWER, not ASCII-only).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_case)
 */
$s = 'über';
echo 'up_var=', mb_convert_case($s, MB_CASE_UPPER), "\n";
echo 'up_lit=', mb_convert_case('über', MB_CASE_UPPER), "\n";
echo 'low_var=', mb_convert_case($s, MB_CASE_LOWER), "\n";
$sharp = 'straße';
echo 'up_sharp=', mb_convert_case($sharp, MB_CASE_UPPER), "\n";
$mixed = 'AbAcAd';
echo 'low_mixed=', mb_convert_case($mixed, MB_CASE_LOWER), "\n";
