--TEST--
stdlib htmlspecialchars() ENT_NOQUOTES and ENT_COMPAT flags (JIT)
--FILE--
<?php
echo htmlspecialchars('a"b\'c', ENT_NOQUOTES), "\n";
echo htmlspecialchars('a"b\'c', ENT_COMPAT), "\n";
echo htmlspecialchars('a"b\'c', ENT_QUOTES), "\n";
--EXPECT--
a"b'c
a&quot;b'c
a&quot;b&#039;c
