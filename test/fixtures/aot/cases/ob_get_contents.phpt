--TEST--
AOT: ob_get_contents() / ob_get_length() / ob_end_clean() (issue #3236)
--FILE--
<?php
echo function_exists('ob_get_contents') ? '1' : '0', "\n";
ob_start();
echo 'hello';
$c = ob_get_contents();
$l = ob_get_length();
ob_end_clean();
echo $c, $l, ob_get_level(), "\n";
--EXPECT--
1
hello50
