--TEST--
stdlib ob_get_flush() — flush buffer and return contents (issue #3753)
--FILE--
<?php
ob_start();
echo 'inner';
$chunk = ob_get_flush();
echo "got:$chunk|";
echo ob_get_level(), "\n";
--EXPECT--
innergot:inner|0
