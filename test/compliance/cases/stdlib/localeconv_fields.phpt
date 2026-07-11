--TEST--
stdlib localeconv() — decimal_point/thousands_sep/currency_symbol via ?? guard (#11603, ext/standard/locale.c)
--FILE--
<?php
declare(strict_types=1);
$lc = localeconv();
echo 'decimal=', var_export($lc['decimal_point'] ?? null, true), "\n";
echo 'thousands=', var_export($lc['thousands_sep'] ?? null, true), "\n";
echo 'currency=', var_export($lc['currency_symbol'] ?? null, true), "\n";
$items = ['page' => 'home'];
echo 'coalesce=', var_export($items['page'] ?? null, true), "\n";
--EXPECT--
decimal='.'
thousands=''
currency=''
coalesce='home'
