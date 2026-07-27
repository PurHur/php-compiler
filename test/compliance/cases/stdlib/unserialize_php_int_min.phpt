--TEST--
stdlib unserialize() restores PHP_INT_MIN (#23689)
--FILE--
<?php
$direct = unserialize('i:-9223372036854775808;');
echo ($direct === PHP_INT_MIN) ? 'direct_ok' : 'direct_fail';
echo "\n";
$round = unserialize(serialize(PHP_INT_MIN));
echo ($round === PHP_INT_MIN) ? 'roundtrip_ok' : 'roundtrip_fail';
echo "\n";
--EXPECT--
direct_ok
roundtrip_ok
