--TEST--
stdlib crypt() $5$ SHA-256 salt returns full hash (#11731, ext/standard/crypt.c)
--FILE--
<?php
$salt = '$5$rounds=1000$usesomesillystringf';
$hash = crypt('pass', $salt);
echo strlen($hash) >= 60 && str_starts_with($hash, '$5$') ? 'ok' : 'fail';
echo "\n";
--EXPECT--
ok
