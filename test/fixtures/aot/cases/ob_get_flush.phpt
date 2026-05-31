--TEST--
AOT: ob_get_flush() with output buffering (issue #3753)
--FILE--
<?php
echo function_exists('ob_get_flush') ? '1' : '0', "\n";
ob_start();
echo 'inner';
ob_get_flush();
echo 'level=', ob_get_level(), "\n";
--EXPECT--
1
innerlevel=0
