<?php

declare(strict_types=1);

/**
 * #34284 — mb_convert_case(MB_CASE_TITLE) runtime UTF-8 under thin AOT (not ASCII-only).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_case)
 */
$s = 'über city';
echo 'title_var=', mb_convert_case($s, MB_CASE_TITLE), "\n";
echo 'title_lit=', mb_convert_case('über city', MB_CASE_TITLE), "\n";
$sharp = 'straße lane';
echo 'title_sharp=', mb_convert_case($sharp, MB_CASE_TITLE), "\n";
$simple = 'über city';
echo 'title_simple=', mb_convert_case($simple, MB_CASE_TITLE_SIMPLE), "\n";
