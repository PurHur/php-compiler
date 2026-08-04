--TEST--
AOT: fprintf(STDOUT) without prior fopen — links __compiler_fwrite (#27677)
--FILE--
<?php
fprintf(STDOUT, "%s\n", "hi");
--EXPECT--
hi
