<?php
/**
 * unserialize('i:-9223372036854775808;') must restore PHP_INT_MIN (#23689, var_unserializer.re).
 */
$direct = unserialize('i:-9223372036854775808;');
echo (string) $direct, "\n";
echo ($direct === PHP_INT_MIN) ? "direct_ok\n" : "direct_fail\n";
$round = unserialize(serialize(PHP_INT_MIN));
echo ($round === PHP_INT_MIN) ? "roundtrip_ok\n" : "roundtrip_fail\n";
