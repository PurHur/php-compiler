--TEST--
stdlib PHP 8.4 — request_parse_body() missing Content-Type → RequestParseBodyException (#5965)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
putenv('CONTENT_TYPE=');
putenv('REQUEST_BODY=a=1');
try {
    request_parse_body();
    echo "no-throw\n";
} catch (RequestParseBodyException $e) {
    echo 'missing:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'other:', get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
missing:Request does not provide a content type
