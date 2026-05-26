--TEST--
stdlib htmlspecialchars_decode() ENT_NOQUOTES and ENT_COMPAT flags (JIT)
--FILE--
<?php
echo htmlspecialchars_decode('&quot;a&#039;b', 0), "\n";
echo htmlspecialchars_decode('&quot;a&#039;b', 2), "\n";
echo htmlspecialchars_decode('&quot;a&#039;b', 3), "\n";
--EXPECT--
&quot;a&#039;b
"a'b
"a'b
