--TEST--
stdlib PHP 8.4 — request_parse_body missing Content-Type does not consume (#21112)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
putenv('CONTENT_TYPE=');
putenv('HTTP_CONTENT_TYPE=');
putenv('REQUEST_BODY=a=1');
try {
    request_parse_body();
    echo "no-throw\n";
} catch (RequestParseBodyException $e) {
    echo 'missing:', $e->getMessage(), "\n";
}
putenv('CONTENT_TYPE=application/x-www-form-urlencoded');
putenv('REQUEST_BODY=a=1&b=two');
[$post, $files] = request_parse_body();
var_export($post);
echo "\n";
?>
--EXPECT--
missing:Request does not provide a content type
array (
  'a' => '1',
  'b' => 'two',
)
