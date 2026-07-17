--TEST--
AOT: ucfirst/lcfirst null — coerce on 8.4 forward profile (#19998)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo ucfirst(null) === '' ? 'ok' : 'bad', "\n";
echo lcfirst(null) === '' ? 'ok' : 'bad', "\n";
--EXPECT--
ok
ok
