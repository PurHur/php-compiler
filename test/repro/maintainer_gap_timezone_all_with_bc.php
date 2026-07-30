<?php
/** Repro #25085 — ALL_WITH_BC count must match Zend on the same tzdata. */
$all = timezone_identifiers_list();
$bc = timezone_identifiers_list(DateTimeZone::ALL_WITH_BC);
$oop = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
echo 'ALL=', count($all), ' BC=', count($bc), ' OOP=', count($oop), "\n";
echo 'extra=', count($bc) - count($all), "\n";
echo 'oop_match=', ($oop === $bc) ? '1' : '0', "\n";
echo 'has_CET=', in_array('CET', $bc, true) ? '1' : '0', "\n";
echo 'has_Brazil_Acre=', in_array('Brazil/Acre', $bc, true) ? '1' : '0', "\n";
echo 'has_Etc_GMT=', in_array('Etc/GMT', $bc, true) ? '1' : '0', "\n";
echo 'has_Factory=', in_array('Factory', $bc, true) ? '1' : '0', "\n";
echo 'has_tzdata_zi=', in_array('tzdata.zi', $bc, true) ? '1' : '0', "\n";
