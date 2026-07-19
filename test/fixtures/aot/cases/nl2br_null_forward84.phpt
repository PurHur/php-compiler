--TEST--
AOT: soft-null on 8.4 forward profile (#21180)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo nl2br(null) === '' ? 'ok' : 'bad', "\n";
--EXPECT--
ok
