--TEST--
stdlib glob() native VM path without host \glob() (#4859)
--FILE--
<?php
$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
$matches = glob($dir . '/*.php');
echo count($matches), "\n";
$n0 = basename($matches[0]);
$n1 = basename($matches[1]);
$pair = ($n0 === 'a.php' && $n1 === 'b.php') || ($n0 === 'b.php' && $n1 === 'a.php');
echo $pair ? 'pair' : 'bad', "\n";
$onlydir = glob($dir . '/*', GLOB_ONLYDIR);
echo count($onlydir) === 0 ? "onlydir_empty\n" : "onlydir_nonempty\n";
--EXPECT--
2
pair
onlydir_empty
