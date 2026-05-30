--TEST--
stdlib unpack() JIT common format codes (issue #3188)
--JIT--
--FILE--
<?php
$r = unpack('N', pack('N', 42));
echo $r[1], "\n";
$r = unpack('c', pack('c', -1));
echo $r[1], "\n";
$r = unpack('C', pack('C', 255));
echo $r[1], "\n";
$r = unpack('n', pack('n', 0x1234));
echo $r[1], "\n";
$r = unpack('v', pack('v', 0x1234));
echo $r[1], "\n";
$r = unpack('N', pack('N', 0x12345678));
echo $r[1], "\n";
$r = unpack('V', pack('V', 0x12345678));
echo $r[1], "\n";
$r = unpack('Nwidth/Nheight', pack('NN', 640, 480));
echo $r['width'], ',', $r['height'], "\n";
$r = unpack('H4', pack('H4', 'dead'));
echo $r[1], "\n";
--EXPECT--
42
-1
255
4660
4660
305419896
305419896
640,480
dead
