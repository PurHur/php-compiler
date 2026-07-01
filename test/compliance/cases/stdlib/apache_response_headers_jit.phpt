--TEST--
stdlib apache_response_headers() JIT alias of headers_list() (issue #6260)
--FILE--
<?php
echo function_exists('apache_response_headers') ? 'yes' : 'no', "\n";
$apache = apache_response_headers();
$list = headers_list();
echo $apache === $list ? 'same' : 'diff', "\n";
?>
--EXPECT--
yes
same
