--TEST--
AOT: vfprintf(STDOUT) without prior fopen — links __compiler_fwrite (#27677)
--FILE--
<?php
vfprintf(STDOUT, "%s\n", ["hi"]);
--EXPECT--
hi
