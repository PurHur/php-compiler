--TEST--
stdlib apache_response_headers() phantom in CLI — function_exists false (issue #14971)
--FILE--
<?php
echo function_exists('apache_response_headers') ? 'yes' : 'no', "\n";
echo function_exists('headers_list') ? 'yes' : 'no', "\n";
?>
--EXPECT--
no
yes
