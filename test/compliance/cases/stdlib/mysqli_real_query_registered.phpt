--TEST--
mysqli_real_query / mysqli::real_query registered (#22249, ext/mysqli/mysqli.stub.php)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
echo 'fn=', function_exists('mysqli_real_query') ? 'Y' : 'N', "\n";
echo 'oo=', method_exists('mysqli', 'real_query') ? 'Y' : 'N', "\n";
echo 'store=', function_exists('mysqli_store_result') ? 'Y' : 'N', "\n";
echo 'use=', function_exists('mysqli_use_result') ? 'Y' : 'N', "\n";
?>
--EXPECT--
fn=Y
oo=Y
store=Y
use=Y
