--TEST--
stdlib readdir() uses filesystem stream order not sorted scandir (#14859)
--FILE--
<?php
$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
$h = opendir($dir);
$first = readdir($h);
closedir($h);
echo ($first === '.' || $first === '..') ? 'dot_first' : 'real_first', "\n";
--EXPECT--
real_first
