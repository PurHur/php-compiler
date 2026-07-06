--TEST--
AOT request_parse_body urlencoded parsing (PHP 8.4 profile, #16927)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
putenv('CONTENT_TYPE=application/x-www-form-urlencoded');
putenv('REQUEST_BODY=a=1&b=two');
[$post, $files] = request_parse_body();
var_export($post);
echo "\n";
var_export($files);
echo "\n";
?>
--EXPECT--
array (
  'a' => '1',
  'b' => 'two',
)
array (
)

