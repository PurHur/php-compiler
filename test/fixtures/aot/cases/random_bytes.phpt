--TEST--
AOT random_bytes() length and bin2hex pairing
--FILE--
<?php
echo strlen(random_bytes(16)), "\n";
echo strlen(bin2hex(random_bytes(8))), "\n";
--EXPECT--
16
16
