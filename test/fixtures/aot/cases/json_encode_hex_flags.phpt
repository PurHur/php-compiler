--TEST--
AOT json_encode() JSON_HEX_TAG (issue #10956)
--FILE--
<?php
echo json_encode('<', JSON_HEX_TAG), "\n";
echo json_encode('<script>', JSON_HEX_TAG), "\n";
?>
--EXPECT--
"\u003C"
"\u003Cscript\u003E"
