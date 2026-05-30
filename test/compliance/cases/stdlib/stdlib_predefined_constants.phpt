--TEST--
stdlib STR_PAD_* / ENT_* / CASE_* predefined constants (#3535, #3638)
--FILE--
<?php
echo str_pad('hi', 5, ' ', STR_PAD_LEFT), "\n";
echo htmlspecialchars('<a>', ENT_QUOTES), "\n";
$constants = get_defined_constants(true);
echo isset($constants['Core']['STR_PAD_LEFT']) && $constants['Core']['STR_PAD_LEFT'] === 0 ? "pad_ok\n" : "pad_bad\n";
echo isset($constants['Core']['ENT_QUOTES']) && $constants['Core']['ENT_QUOTES'] === 3 ? "ent_ok\n" : "ent_bad\n";
echo isset($constants['Core']['CASE_LOWER']) && $constants['Core']['CASE_LOWER'] === 0 ? "case_lo_ok\n" : "case_lo_bad\n";
echo isset($constants['Core']['CASE_UPPER']) && $constants['Core']['CASE_UPPER'] === 1 ? "case_up_ok\n" : "case_up_bad\n";
$hi = array_change_key_case(array('Ab' => 1), CASE_UPPER);
echo $hi['AB'], "\n";
--EXPECT--
   hi
&lt;a&gt;
pad_ok
ent_ok
case_lo_ok
case_up_ok
1
