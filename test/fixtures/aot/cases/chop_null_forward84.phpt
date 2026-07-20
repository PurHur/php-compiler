--TEST--
AOT: chop null — coerce on 8.4 forward profile (#21404)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo chop(null) === '' ? 'ok' : 'bad', "\n";
--EXPECT--
ok
