--TEST--
stdlib STR_PAD_* / ENT_* / CASE_* / M_* / PATHINFO_* predefined constants (#3535, #3638, #3660, #3651)
--FILE--
<?php
echo str_pad('hi', 5, ' ', STR_PAD_LEFT), "\n";
echo htmlspecialchars('<a>', ENT_QUOTES), "\n";
$constants = get_defined_constants(true);
echo isset($constants['Core']['STR_PAD_LEFT']) && $constants['Core']['STR_PAD_LEFT'] === 0 ? "pad_ok\n" : "pad_bad\n";
echo isset($constants['Core']['ENT_QUOTES']) && $constants['Core']['ENT_QUOTES'] === 3 ? "ent_ok\n" : "ent_bad\n";
echo isset($constants['Core']['CASE_LOWER']) && $constants['Core']['CASE_LOWER'] === 0 ? "case_lo_ok\n" : "case_lo_bad\n";
echo isset($constants['Core']['CASE_UPPER']) && $constants['Core']['CASE_UPPER'] === 1 ? "case_up_ok\n" : "case_up_bad\n";
echo isset($constants['Core']['PATHINFO_EXTENSION']) && $constants['Core']['PATHINFO_EXTENSION'] === 4 ? "pathinfo_ext_ok\n" : "pathinfo_ext_bad\n";
echo isset($constants['Core']['PATHINFO_ALL']) && $constants['Core']['PATHINFO_ALL'] === 15 ? "pathinfo_all_ok\n" : "pathinfo_all_bad\n";
$hi = array_change_key_case(array('Ab' => 1), CASE_UPPER);
echo $hi['AB'], "\n";
echo defined('M_PI') && defined('M_E') && defined('M_LOG2E') ? "math_defined_ok\n" : "math_defined_bad\n";
echo abs(M_PI - 3.1415926535898) < 1e-10 ? "pi_ok\n" : "pi_bad\n";
echo abs(M_E - 2.718281828459) < 1e-10 ? "e_ok\n" : "e_bad\n";
echo abs(M_LOG2E - 1.4426950408889) < 1e-10 ? "log2e_ok\n" : "log2e_bad\n";
echo isset($constants['Core']['M_PI']) ? "m_pi_listed\n" : "m_pi_missing\n";
--EXPECT--
   hi
&lt;a&gt;
pad_ok
ent_ok
case_lo_ok
case_up_ok
pathinfo_ext_ok
pathinfo_all_ok
1
math_defined_ok
pi_ok
e_ok
log2e_ok
m_pi_listed
