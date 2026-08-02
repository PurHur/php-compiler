--TEST--
AOT: pack()/unpack() basic formats + n* round-trip (#26862)
--FILE--
<?php
echo bin2hex(pack('n', 0x1234)), "\n";
var_export(unpack('n', "\x12\x34"));
echo "\n";
$b = pack('n*', 1, 2, 3);
$u = unpack('n*', $b);
echo implode(',', $u), "\n";
--EXPECT--
1234
array (
  1 => 4660,
)
1,2,3
