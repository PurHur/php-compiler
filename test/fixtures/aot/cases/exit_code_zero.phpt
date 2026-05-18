--TEST--
AOT: standalone main returns exit code 0 (C ABI)
--FILE--
<?php
echo "ok\n";
--EXPECT--
ok
--EXPECT_EXIT--
0
