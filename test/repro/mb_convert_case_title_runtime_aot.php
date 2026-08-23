<?php

declare(strict_types=1);

/**
 * #34284 — mb_convert_case() TITLE on runtime UTF-8 under thin AOT (not ASCII-only peel).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_case)
 */
$s = 'über city';
echo 'title_var=', mb_convert_case($s, MB_CASE_TITLE), "\n";
echo 'title_lit=', mb_convert_case('über city', MB_CASE_TITLE), "\n";
$titleSimple = 'über city';
echo 'title_simple=', mb_convert_case($titleSimple, MB_CASE_TITLE_SIMPLE), "\n";
$up = 'über';
echo 'up_var=', mb_convert_case($up, MB_CASE_UPPER), "\n";
