--TEST--
stdlib pack() common format codes
--FILE--
<?php
echo bin2hex(pack('c', 65)), "\n";
echo bin2hex(pack('C', 255)), "\n";
echo bin2hex(pack('n', 0x1234)), "\n";
echo bin2hex(pack('v', 0x1234)), "\n";
echo bin2hex(pack('N', 0x12345678)), "\n";
echo bin2hex(pack('V', 0x12345678)), "\n";
echo bin2hex(pack('a3', 'hi')), "\n";
echo bin2hex(pack('A3', 'hi')), "\n";
echo bin2hex(pack('H4', 'dead')), "\n";
echo bin2hex(pack('h4', 'dead')), "\n";
echo bin2hex(pack('x2')), "\n";
echo bin2hex(pack('@4c', 65)), "\n";
--EXPECT--
41
ff
1234
3412
12345678
78563412
686900
686920
dead
edda
0000
0000000041
