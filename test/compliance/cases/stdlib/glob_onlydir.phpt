--TEST--
stdlib glob() GLOB_ONLYDIR constant and directory filter (#3523)
--FILE--
<?php
$dir = 'test/compliance/cases/stdlib/glob_onlydir_fixture';
echo defined('GLOB_ONLYDIR') && GLOB_ONLYDIR === 8192 ? "const_ok\n" : "const_bad\n";
$matches = glob($dir . '/*', GLOB_ONLYDIR);
echo count($matches), "\n";
echo count($matches) === 1 && basename($matches[0]) === 'subdir' ? "onlydir_ok\n" : "onlydir_bad\n";
echo count(glob($dir . '/*', GLOB_NOSORT)), "\n";
--EXPECT--
const_ok
1
onlydir_ok
2
