--TEST--
stdlib disktotalspace() alias on 8.2 reference profile (ext/standard/filestat.c, #17821)
--FILE--
<?php
echo function_exists('disk_total_space') ? 'total=1' : 'total=0', "\n";
echo function_exists('disktotalspace') ? 'alias=1' : 'alias=0', "\n";
$path = sys_get_temp_dir();
$direct = disk_total_space($path);
$alias = disktotalspace($path);
echo ($direct === $alias) ? 'equal=1' : 'equal=0', "\n";
--EXPECT--
total=1
alias=1
equal=1
