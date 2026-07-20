--TEST--
AOT: rtrim null — coerce on 8.4 forward profile (#21404)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo rtrim(null) === '' ? 'ok' : 'bad', "\n";
--EXPECT--
ok
