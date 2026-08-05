--TEST--
AOT: localeconv() decimal_point + grouping shape (#27029; ext/standard/locale.c)
--FILE--
<?php
$lc = localeconv();
echo $lc['decimal_point'], "\n";
echo is_array($lc['grouping']) ? '1' : '0', "\n";
echo is_array($lc['mon_grouping']) ? '1' : '0', "\n";
echo $lc['int_frac_digits'], "\n";
--EXPECT--
.
1
1
127
