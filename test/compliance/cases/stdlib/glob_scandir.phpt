--TEST--
stdlib glob() and scandir()
--FILE--
<?php
$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
$matches = glob($dir . '/*.php');
echo count($matches), "\n";
$n0 = basename($matches[0]);
$n1 = basename($matches[1]);
$pair = ($n0 === 'a.php' && $n1 === 'b.php') || ($n0 === 'b.php' && $n1 === 'a.php');
echo $pair ? 'pair' : 'bad', "\n";
$entries = scandir($dir);
echo in_array('a.php', $entries, true) ? 'has_a' : 'no_a', "\n";
echo in_array('readme.txt', $entries, true) ? 'has_txt' : 'no_txt', "\n";
echo scandir('/nonexistent/path/for/php-compiler') === false ? 'false' : 'ok', "\n";
--EXPECT--
2
pair
has_a
has_txt
false
