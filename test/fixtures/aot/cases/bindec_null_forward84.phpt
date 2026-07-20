--TEST--
AOT: bindec/hexdec/octdec null — soft-null on 8.4 forward profile (#21244, reverts #20658)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo bindec(null), ',', hexdec(null), ',', octdec(null), "\n";
?>
--EXPECT--
0,0,0
