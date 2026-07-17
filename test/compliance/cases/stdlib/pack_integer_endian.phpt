--TEST--
stdlib pack()/unpack() machine-endian integers + @ alignment (#4675)
--FILE--
<?php
echo bin2hex(pack('n', 0x1234)), "\n";
echo unpack('n', pack('n', 0x1234))[1], "\n";
echo bin2hex(pack('v', 0x1234)), "\n";
echo unpack('v', pack('v', 0x1234))[1], "\n";
echo bin2hex(pack('N', 0x12345678)), "\n";
echo unpack('N', pack('N', 0x12345678))[1], "\n";
echo bin2hex(pack('V', 0x12345678)), "\n";
echo unpack('V', pack('V', 0x12345678))[1], "\n";
echo bin2hex(pack('c', -2)), "\n";
echo unpack('c', pack('c', -2))[1], "\n";
echo bin2hex(pack('C', 254)), "\n";
echo unpack('C', pack('C', 254))[1], "\n";
echo bin2hex(pack('s', -2)), "\n";
echo unpack('s', pack('s', -2))[1], "\n";
echo bin2hex(pack('S', 0xFFFE)), "\n";
echo unpack('S', pack('S', 0xFFFE))[1], "\n";
echo bin2hex(pack('i', -2)), "\n";
echo unpack('i', pack('i', -2))[1], "\n";
echo bin2hex(pack('I', 0xFFFFFFFE)), "\n";
echo unpack('I', pack('I', 0xFFFFFFFE))[1], "\n";
echo bin2hex(pack('l', -2)), "\n";
echo unpack('l', pack('l', -2))[1], "\n";
echo bin2hex(pack('L', 0xFFFFFFFE)), "\n";
echo unpack('L', pack('L', 0xFFFFFFFE))[1], "\n";
echo bin2hex(pack('q', -2)), "\n";
echo unpack('q', pack('q', -2))[1], "\n";
echo bin2hex(pack('Q', 0x123456789abcdef0)), "\n";
echo unpack('Q', pack('Q', 0x123456789abcdef0))[1], "\n";
echo bin2hex(pack('J', 0x0102030405060708)), "\n";
echo unpack('J', pack('J', 0x0102030405060708))[1], "\n";
echo bin2hex(pack('P', 0x0102030405060708)), "\n";
echo unpack('P', pack('P', 0x0102030405060708))[1], "\n";
echo strlen(pack('I@4I', 1, 2)), "\n";
echo bin2hex(pack('I@4I', 1, 2)), "\n";
echo bin2hex(pack('a2@4a2', 'ab', 'cd')), "\n";
echo bin2hex(pack('Z*', 'hi')), "\n";
echo bin2hex(pack('a2x2a2', 'ab', 'cd')), "\n";
$bin = pack('Nn', 0x11223344, 0x5566);
$u = unpack('Nn', $bin);
echo $u['n'], "\n";
--EXPECT--
1234
4660
3412
4660
12345678
305419896
78563412
305419896
fe
-2
fe
254
feff
-2
feff
65534
feffffff
-2
feffffff
4294967294
feffffff
-2
feffffff
4294967294
feffffffffffffff
-2
f0debc9a78563412
1311768467463790320
0102030405060708
72623859790382856
0807060504030201
72623859790382856
8
0100000002000000
616200006364
686900
616200006364
287454020
