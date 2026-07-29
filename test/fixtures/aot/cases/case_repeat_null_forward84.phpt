--TEST--
AOT: ucfirst/lcfirst/str_repeat null — coerce on 8.4 forward profile (#24598, reverts #24213)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo ucfirst(null) === '' ? 'ok' : 'bad', "\n";
echo lcfirst(null) === '' ? 'ok' : 'bad', "\n";
echo str_repeat(null, 1) === '' ? 'ok' : 'bad', "\n";
--EXPECT--
ok
ok
ok
