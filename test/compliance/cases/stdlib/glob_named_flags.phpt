--TEST--
stdlib glob() named flags parameter (#17091)
--FILE--
<?php
$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
$named = glob($dir . '/*.php', flags: GLOB_NOSORT);
$positional = glob($dir . '/*.php', GLOB_NOSORT);
echo count($named), "\n";
echo $named === $positional ? "same\n" : "diff\n";
?>
--EXPECT--
2
same
