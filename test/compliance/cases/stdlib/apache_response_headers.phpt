--TEST--
stdlib apache_response_headers() alias of headers_list() (issue #6260)
--ENV--
REQUEST_METHOD=GET
--FILE--
<?php
echo function_exists('apache_response_headers') ? 'yes' : 'no', "\n";
echo function_exists('headers_list') ? 'yes' : 'no', "\n";
$apache = apache_response_headers();
$list = headers_list();
echo $apache === $list ? 'same' : 'diff', "\n";
?>
--EXPECT--
yes
yes
same
