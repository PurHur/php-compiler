--TEST--
JIT: feof() invalid handle via __compiler_feof (issue #1188)
--FILE--
<?php
echo feof(-999) ? '1' : '0';
--EXPECT--
1
