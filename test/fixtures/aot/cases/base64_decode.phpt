--TEST--
AOT: base64_decode() roundtrip
--FILE--
<?php
echo base64_decode('Zm9v'), "\n";
--EXPECT--
foo
--EXPECT_EXIT--
0