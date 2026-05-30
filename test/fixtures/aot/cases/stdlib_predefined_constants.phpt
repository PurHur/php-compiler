--TEST--
AOT: STR_PAD_* / ENT_* / M_* predefined constants (#3535, #3660)
--FILE--
<?php
echo (STR_PAD_LEFT === 0 && STR_PAD_BOTH === 2 && ENT_QUOTES === 3) ? "const_ok\n" : "const_bad\n";
echo htmlspecialchars('a"b', ENT_COMPAT), "\n";
echo (defined('M_PI') && defined('M_E') && defined('M_LOG2E')) ? "math_ok\n" : "math_bad\n";
--EXPECT--
const_ok
a&quot;b
math_ok
