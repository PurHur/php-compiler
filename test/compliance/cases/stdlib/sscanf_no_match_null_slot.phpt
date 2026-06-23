--TEST--
stdlib sscanf() two-arg no-match returns NULL slot (#10919, ext/standard/sscanf.c)
--FILE--
<?php
declare(strict_types=1);
var_export(sscanf('abc', '%d'));
?>
--EXPECT--
array (
  0 => NULL,
)
