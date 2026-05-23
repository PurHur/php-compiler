--TEST--
AOT: base64_encode() empty, binary, and text
--FILE--
<?php
echo base64_encode(''), "\n";
echo base64_encode('abc'), "\n";
echo base64_encode("\x00\xff"), "\n";
--EXPECT--

YWJj
AP8=
