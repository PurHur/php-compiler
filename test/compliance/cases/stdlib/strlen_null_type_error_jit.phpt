--TEST--
stdlib strlen() JIT — null deprecated, returns 0 (#5000)
--FILE--
<?php
echo strlen(null), "\n";
--EXPECT--
0
