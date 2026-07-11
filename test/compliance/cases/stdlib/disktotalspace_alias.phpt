--TEST--
stdlib disktotalspace() alias absent on 8.2 reference profile (ext/standard/filestat.c, #18017)
--FILE--
<?php
echo function_exists('disk_total_space') ? 'total=1' : 'total=0', "\n";
echo function_exists('disktotalspace') ? 'alias=1' : 'alias=0', "\n";
--EXPECT--
total=1
alias=0
