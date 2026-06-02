--TEST--
AOT: pack()/unpack() basic formats + var_export array output
--FILE--
<?php
echo bin2hex(pack('n', 0x1234)), "\n";
var_export(unpack('n', "\x12\x34"));
echo "\n";
--EXPECT--
1234
array (
  1 => 4660,
)

