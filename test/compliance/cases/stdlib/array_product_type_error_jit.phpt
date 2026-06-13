--TEST--
stdlib array_product() JIT — non-numeric string contributes zero (#4262, #4278)
--FILE--
<?php
echo array_product([1, 'x']), "\n";
--EXPECT--
0
