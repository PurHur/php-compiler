--TEST--
AOT: urlencode(null) soft-null on 8.4 forward profile (#21188, ext/standard/url.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo urlencode(null) === '' ? 'ok' : 'bad', "\n";
--EXPECT--
ok
