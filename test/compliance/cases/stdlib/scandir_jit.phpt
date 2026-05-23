--TEST--
JIT: scandir() via libc scandir(3)
--FILE--
<?php
$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
$entries = scandir($dir);
echo in_array('a.php', $entries, true) ? 'has_a' : 'no_a', "\n";
echo in_array('readme.txt', $entries, true) ? 'has_txt' : 'no_txt', "\n";
echo @scandir('/nonexistent/path/for/php-compiler') === false ? 'false' : 'ok', "\n";
--EXPECT--
has_a
has_txt
false
