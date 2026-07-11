--TEST--
get_defined_constants(true) standard bucket includes M_* float constants (#17831, ext/standard/basic_functions.c)
--FILE--
<?php
$c = get_defined_constants(true);
echo isset($c['standard']['M_PI']) && is_float($c['standard']['M_PI']) ? "m_pi_ok\n" : "m_pi_bad\n";
echo isset($c['standard']['M_E']) && is_float($c['standard']['M_E']) ? "m_e_ok\n" : "m_e_bad\n";
echo count($c['standard']) >= 320 ? "standard_count_ok\n" : "standard_count_bad\n";
--EXPECT--
m_pi_ok
m_e_ok
standard_count_ok
