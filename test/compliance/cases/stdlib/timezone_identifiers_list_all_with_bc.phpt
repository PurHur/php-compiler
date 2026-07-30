--TEST--
stdlib timezone_identifiers_list(ALL_WITH_BC) matches Zend BC alias completeness (#25085, ext/date)
--FILE--
<?php
$all = timezone_identifiers_list();
$bc = timezone_identifiers_list(DateTimeZone::ALL_WITH_BC);
$oop = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
echo 'ALL=', count($all), "\n";
echo 'BC=', count($bc), "\n";
echo 'oop_match=', ($oop === $bc) ? '1' : '0', "\n";
echo 'has_CET=', in_array('CET', $bc, true) ? '1' : '0', "\n";
echo 'has_Brazil_Acre=', in_array('Brazil/Acre', $bc, true) ? '1' : '0', "\n";
echo 'has_Etc_GMT=', in_array('Etc/GMT', $bc, true) ? '1' : '0', "\n";
echo 'has_Factory=', in_array('Factory', $bc, true) ? '1' : '0', "\n";
echo 'bc_ge_all=', (count($bc) >= count($all)) ? '1' : '0', "\n";
?>
--EXPECTF--
ALL=%d
BC=%d
oop_match=1
has_CET=1
has_Brazil_Acre=1
has_Etc_GMT=1
has_Factory=1
bc_ge_all=1
