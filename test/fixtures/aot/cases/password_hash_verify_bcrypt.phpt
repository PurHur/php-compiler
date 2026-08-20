--TEST--
AOT: password_hash/password_verify bcrypt via prelinked PasswordJitHelper (#33027)
--FILE--
<?php
$h = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
echo is_string($h) ? 'S' : 'N';
echo strlen($h);
echo '|';
echo password_verify('x', $h) ? '1' : '0';
echo '|';
echo password_verify('y', $h) ? '1' : '0';
echo "\n";
--EXPECT--
S60|1|0
