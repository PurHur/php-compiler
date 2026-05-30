--TEST--
AOT: STR_PAD_* and ENT_* predefined constants (#3535)
--FILE--
<?php
echo (STR_PAD_LEFT === 0 && STR_PAD_BOTH === 2 && ENT_QUOTES === 3) ? "const_ok\n" : "const_bad\n";
echo htmlspecialchars('a"b', ENT_COMPAT), "\n";
--EXPECT--
const_ok
a&quot;b
