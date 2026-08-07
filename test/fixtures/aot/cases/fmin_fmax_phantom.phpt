--TEST--
AOT: fmin()/fmax() phantoms absent under PROFILE≥8.4 (#28565)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('fmin') ? "fmin-fail\n" : "fmin-ok\n";
echo function_exists('fmax') ? "fmax-fail\n" : "fmax-ok\n";
echo function_exists('fpow') ? "fpow-ok\n" : "fpow-fail\n";
?>
--EXPECT--
fmin-ok
fmax-ok
fpow-ok
