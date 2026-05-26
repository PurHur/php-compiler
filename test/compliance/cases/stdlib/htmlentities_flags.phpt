--TEST--
stdlib htmlentities() ENT_* flags (#2472)
--FILE--
<?php
echo htmlentities('a"b\'c', 0), "\n";
echo htmlentities('a"b\'c', 2), "\n";
echo htmlentities('a"b\'c', 3), "\n";
--EXPECT--
a"b'c
a&quot;b'c
a&quot;b&#039;c
