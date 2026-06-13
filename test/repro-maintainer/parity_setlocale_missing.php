<?php

foreach (['setlocale', 'localeconv', 'strcoll'] as $f) {
    echo $f, ': ', function_exists($f) ? 'yes' : 'no', "\n";
}

setlocale(LC_ALL, 'C');
echo 'set C query null: ', var_export(setlocale(LC_ALL, null), true), "\n";
echo 'strcoll a/b: ', strcoll('a', 'b'), "\n";

$lc = localeconv();
echo 'decimal_point: ', $lc['decimal_point'], "\n";
echo 'thousands_sep: ', $lc['thousands_sep'], "\n";
