--TEST--
stdlib unserialize() restores backed enum case singleton (#5739, var_unserializer.c)
--FILE--
<?php
declare(strict_types=1);
enum E: int { case A = 1; }
$s = serialize(E::A);
echo $s, "\n";
$u = unserialize($s);
var_export($u);
echo "\n", ($u === E::A) ? "same\n" : "diff\n";
$z = unserialize('E:3:"E:A";');
echo ($z === E::A) ? "zend_payload\n" : "zend_bad\n";
--EXPECT--
E:3:"E:A";
\E::A
same
zend_payload
