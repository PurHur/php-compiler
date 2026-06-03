--TEST--
Runtime: stream/dir resource == and === compare handle identity (#4699)
--FILE--
<?php
$a = fopen('php://memory', 'r+');
$b = fopen('php://memory', 'r+');
var_dump($a === $b);
var_dump($a == $b);
var_dump(is_resource($a), is_resource($b));
$id = (int) $a;
var_dump($a === $id);
var_dump($id === $a);
var_dump($a == $id);
var_dump($id == $a);

$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
$dh1 = opendir($dir);
$dh2 = opendir($dir);
var_dump($dh1 === $dh2);
var_dump($dh1 == $dh2);
var_dump($a === $dh1);
?>
--EXPECT--
bool(false)
bool(false)
bool(true)
bool(true)
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
