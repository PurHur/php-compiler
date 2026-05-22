--TEST--
stdlib htmlspecialchars() ENT_NOQUOTES and ENT_COMPAT flags (JIT)
--FILE--
<?php
echo htmlspecialchars('a"b\'c', 0), "\n";
echo htmlspecialchars('a"b\'c', 2), "\n";
echo htmlspecialchars('a"b\'c', 3), "\n";
--EXPECT--
a"b'c
a&quot;b&#039;c
a&quot;b&#039;c
