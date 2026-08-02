--TEST--
AOT json_encode() PARTIAL keeps JSON_ERROR_INF_OR_NAN sticky (#26792)
--FILE--
<?php
$r = json_encode(['x' => INF], JSON_PARTIAL_OUTPUT_ON_ERROR);
echo 'out=', $r, "\n";
echo 'err=', json_last_error(), "\n";
echo 'msg=', json_last_error_msg(), "\n";
--EXPECT--
out={"x":0}
err=7
msg=Inf and NaN cannot be JSON encoded
