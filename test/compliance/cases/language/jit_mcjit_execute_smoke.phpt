--TEST--
JIT: MCJIT execute smoke — echo literal (#98, #8721)
--FILE--
<?php
echo 1;

--EXPECT--
1
