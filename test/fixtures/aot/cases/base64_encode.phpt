--TEST--
AOT: base64_encode() subset
--FILE--
<?php
echo base64_encode(''), "\n";
echo base64_encode('foo'), "\n";
--EXPECT--

Zm9v
--EXPECT_EXIT--
0