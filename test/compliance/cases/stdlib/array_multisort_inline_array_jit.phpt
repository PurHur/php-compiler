--TEST--
stdlib array_multisort() JIT — inline non-empty array literals (#12017, ext/standard/array.c)
--FILE--
<?php
array_multisort([3, 1, 2], SORT_ASC, SORT_NUMERIC);
echo "ok\n";
--EXPECT--
ok
