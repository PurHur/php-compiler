<?php
// #33027 — AOT password_hash must not SIGSEGV when linking prelinked PasswordJitHelper
$h = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
echo is_string($h) ? 'S' : 'N';
echo strlen($h);
echo '|';
echo password_verify('x', $h) ? '1' : '0';
echo "\n";
