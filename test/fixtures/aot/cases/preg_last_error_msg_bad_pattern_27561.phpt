--TEST--
AOT preg_last_error_msg after bad pattern is Internal error (#27561)
--FILE--
<?php
@preg_match('/(/', 'x');
echo preg_last_error_msg();
?>
--EXPECT--
Internal error
