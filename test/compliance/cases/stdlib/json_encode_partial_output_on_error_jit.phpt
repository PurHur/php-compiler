--TEST--
stdlib json_encode() JSON_PARTIAL_OUTPUT_ON_ERROR JIT (issue #10954)
--FILE--
<?php
echo json_encode(NAN, JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
echo json_encode([NAN], JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
?>
--EXPECT--
0
[0]
