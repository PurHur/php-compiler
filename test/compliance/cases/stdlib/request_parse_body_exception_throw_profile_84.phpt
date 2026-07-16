--TEST--
stdlib PHP 8.4 — request_parse_body() unsupported Content-Type → RequestParseBodyException (#5965)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
putenv('CONTENT_TYPE=application/json');
putenv('REQUEST_BODY={}');
try {
    request_parse_body();
    echo "no-throw\n";
} catch (RequestParseBodyException $e) {
    echo 'unsupported:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'other:', get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
unsupported:Content-Type application/json is not supported
