--TEST--
stdlib json_encode() JSON_PARTIAL_OUTPUT_ON_ERROR for INF/NAN (issue #10954)
--FILE--
<?php
echo json_encode(NAN, JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
echo json_encode([NAN], JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
echo json_encode(INF, JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
echo json_last_error() === JSON_ERROR_INF_OR_NAN ? '7' : 'n', "\n";
?>
--EXPECT--
0
[0]
0
7
