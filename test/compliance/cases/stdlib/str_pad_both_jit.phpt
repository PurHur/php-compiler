--TEST--
stdlib str_pad() STR_PAD_BOTH JIT
--FILE--
<?php
echo str_pad('a', 5, '_', 2), "\n";
--EXPECT--
__a__
