--TEST--
stdlib strncasecmp()
--FILE--
<?php
echo strncasecmp('abc', 'ABD', 2), "\n";
echo strncasecmp('abc', 'ABC', 3), "\n";
echo strncasecmp('abd', 'ABC', 3), "\n";
--EXPECT--
0
0
1
