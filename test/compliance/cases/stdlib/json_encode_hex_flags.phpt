--TEST--
stdlib json_encode() JSON_HEX_* flags (issue #10956)
--FILE--
<?php
echo json_encode('<', JSON_HEX_TAG), "\n";
echo json_encode('<script>', JSON_HEX_TAG), "\n";
$s = "<&>\"'";
echo json_encode($s, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), "\n";
echo JSON_HEX_TAG | JSON_HEX_AMP, "\n";
echo json_encode('<', JSON_HEX_TAG | JSON_HEX_AMP);
var_export(JSON_HEX_TAG | JSON_HEX_AMP);
echo "\n";
?>
--EXPECT--
"\u003C"
"\u003Cscript\u003E"
"\u003C\u0026\u003E\u0022\u0027"
3
"\u003C"3
