--TEST--
AOT: addcslashes/stripcslashes soft-null on 8.4 forward profile (#21220)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo addcslashes(null, 'a') === '' && stripcslashes(null) === '' ? 'ok' : 'bad', "\n";
--EXPECT--
ok
