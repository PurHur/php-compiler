--TEST--
stdlib fastcgi_finish_request() phantom in CLI — function_exists false (issues #3466, #16757)
--FILE--
<?php
echo function_exists('fastcgi_finish_request') ? 'exists' : 'missing', "\n";
?>
--EXPECT--
missing
