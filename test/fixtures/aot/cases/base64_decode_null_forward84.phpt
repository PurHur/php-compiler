--TEST--
AOT: base64_decode(null) soft-null on 8.4 forward profile (#21188)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo base64_decode(null) === '' ? 'ok' : 'bad', "\n";
--EXPECT--
ok
