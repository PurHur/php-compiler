--TEST--
stdlib localeconv() grouping int[] and CHAR_MAX char fields (#10265, ext/standard/locale.c)
--FILE--
<?php
$lc = localeconv();
echo $lc['int_frac_digits'], "\n";
echo is_array($lc['grouping']) ? '1' : '0', "\n";
echo is_array($lc['mon_grouping']) ? '1' : '0', "\n";
echo count($lc['grouping']), "\n";
echo count($lc['mon_grouping']), "\n";
--EXPECT--
127
1
1
0
0
