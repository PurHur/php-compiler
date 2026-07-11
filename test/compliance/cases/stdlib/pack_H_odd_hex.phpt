--TEST--
stdlib pack() H/h odd-length hex nibble (#12217, ext/standard/pack.c)
--FILE--
<?php
echo pack('H', '4142'), "\n";
echo pack('H2', '4142'), "\n";
echo pack('H*', '4142'), "\n";
echo bin2hex(pack('h', '4142')), "\n";
--EXPECT--
@
A
AB
04
