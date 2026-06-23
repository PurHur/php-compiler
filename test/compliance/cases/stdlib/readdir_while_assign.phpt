--TEST--
stdlib readdir() while-assign condition and array append (#10702)
--FILE--
<?php
$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
$dh = opendir($dir);
$names = [];
while (($file = readdir($dh)) !== false) {
    $names[] = $file;
}
echo count($names), "\n";
echo in_array('a.php', $names, true) ? 'has_a' : 'no_a', "\n";
closedir($dh);
echo "closedir_ok\n";
--EXPECT--
5
has_a
closedir_ok
