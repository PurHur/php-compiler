--TEST--
stdlib ob_implicit_flush() nested buffer flush (issue #3401)
--FILE--
<?php
echo function_exists('ob_implicit_flush') ? '1' : '0', "\n";
ob_start();
ob_start();
ob_implicit_flush(true);
echo 'x';
$clean = ob_get_clean();
echo 'clean=', $clean, '|level=', ob_get_level(), "\n";
ob_end_flush();
--EXPECT--
1
clean=x|level=1
