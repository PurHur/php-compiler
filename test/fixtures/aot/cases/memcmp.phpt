--TEST--
AOT: memcmp() binary compare (#7118)
--FILE--
<?php
echo memcmp('abc', 'abd', 3), "\n";
echo memcmp('abc', 'ab', 3), "\n";
--EXPECT--
-1
1
