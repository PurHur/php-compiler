--TEST--
AOT: strrev null — coerce on 8.4 forward profile (#20007)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo strrev(null) === '' ? 'ok' : 'bad', "\n";
--EXPECT--
ok
