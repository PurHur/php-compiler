--TEST--
stdlib STR_PAD_* / ENT_* predefined constants (#3535)
--FILE--
<?php
echo str_pad('hi', 5, ' ', STR_PAD_LEFT), "\n";
echo htmlspecialchars('<a>', ENT_QUOTES), "\n";
$constants = get_defined_constants(true);
echo isset($constants['Core']['STR_PAD_LEFT']) && $constants['Core']['STR_PAD_LEFT'] === 0 ? "pad_ok\n" : "pad_bad\n";
echo isset($constants['Core']['ENT_QUOTES']) && $constants['Core']['ENT_QUOTES'] === 3 ? "ent_ok\n" : "ent_bad\n";
--EXPECT--
   hi
&lt;a&gt;
pad_ok
ent_ok
