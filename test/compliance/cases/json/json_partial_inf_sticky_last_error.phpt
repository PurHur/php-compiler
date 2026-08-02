--TEST--
json_encode() JSON_PARTIAL_OUTPUT_ON_ERROR keeps JSON_ERROR_INF_OR_NAN (#26792)
--FILE--
<?php
$r = json_encode(['x' => INF], JSON_PARTIAL_OUTPUT_ON_ERROR);
echo 'out=', $r, "\n";
echo 'err=', json_last_error(), "\n";
echo 'msg=', json_last_error_msg(), "\n";
$r2 = json_encode(['x' => INF]);
echo 'nof=', ($r2 === false ? 'false' : $r2), ' err=', json_last_error(), "\n";
$r3 = json_encode(['x' => NAN], JSON_PARTIAL_OUTPUT_ON_ERROR);
echo 'nan=', $r3, ' err=', json_last_error(), "\n";
--EXPECT--
out={"x":0}
err=7
msg=Inf and NaN cannot be JSON encoded
nof=false err=7
nan={"x":0} err=7
