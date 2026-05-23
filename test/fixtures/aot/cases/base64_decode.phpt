--TEST--
AOT: base64_decode() round-trip and ignored junk
--FILE--
<?php
echo base64_encode(base64_decode('YQ==')), "\n";
echo base64_encode(base64_decode('aGVsbG8=')), "\n";
echo base64_encode(base64_decode('!!!')), "\n";
--EXPECT--
YQ==
aGVsbG8=

