--TEST--
AOT: str_contains null haystack — soft-null on 8.4 forward profile (#21187)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo str_contains(null, 'x') === false ? 'ok' : 'bad', "\n";
--EXPECT--
ok
