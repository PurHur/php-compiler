--TEST--
stdlib htmlspecialchars_decode() ENT_* flags (#2454)
--FILE--
<?php
echo htmlspecialchars_decode('a&quot;b&#039;c', 0), "\n";
echo htmlspecialchars_decode('a&quot;b&#039;c', 2), "\n";
echo htmlspecialchars_decode('a&quot;b&#039;c', 3), "\n";
echo htmlspecialchars_decode('&#39;x', 3), "\n";
--EXPECT--
a&quot;b&#039;c
a"b'c
a"b'c
'x
