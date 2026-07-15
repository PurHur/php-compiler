--TEST--
AOT request_parse_body urlencoded parsing (PHP 8.4 profile, #16927)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
putenv('CONTENT_TYPE=application/x-www-form-urlencoded');
putenv('REQUEST_BODY=a=1&b=two');
$pair = request_parse_body();
echo $pair[0]['a'], "\n";
?>
--EXPECT--
1

