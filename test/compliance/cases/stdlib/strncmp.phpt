--TEST--
stdlib strncmp()
--FILE--
<?php
echo strncmp('abc', 'abd', 2), "\n";
echo strncmp('abc', 'abc', 3), "\n";
echo strncmp('abd', 'abc', 3), "\n";
echo strncmp('a', 'A', 1), "\n";
echo strcmp('a', '1'), "\n";
--EXPECT--
0
0
1
32
48
