--TEST--
stdlib stat()/lstat() — works with PHP_COMPILER_DISABLE_FFI=1 (VmStatPure path, #8903)
--ENV--
PHP_COMPILER_DISABLE_FFI=1
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
$s = stat($path);
echo ($s !== false && isset($s['mtime'])) ? "stat_mtime\n" : "stat_fail\n";
echo ($s !== false && $s[9] === $s['mtime']) ? "stat_idx\n" : "stat_noidx\n";
$l = lstat($path);
echo ($l !== false && isset($l['mode'])) ? "lstat_mode\n" : "lstat_fail\n";
$missing = stat('/no/such/phpc-stat-pure-'.bin2hex(random_bytes(4)));
echo $missing === false ? "missing_false\n" : "missing_bad\n";
--EXPECTF--
%A
stat_mtime
stat_idx
lstat_mode
missing_false
