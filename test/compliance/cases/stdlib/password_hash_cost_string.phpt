--TEST--
stdlib password_hash() — numeric string options cost coerces (#11766, ext/standard/password.c)
--FILE--
<?php
$h = password_hash('pw', PASSWORD_BCRYPT, ['cost' => '10']);
echo is_string($h) && str_starts_with($h, '$2y$10$') && password_verify('pw', $h) ? "ok\n" : "fail\n";
--EXPECT--
ok
