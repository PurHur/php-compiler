<?php
// #23360 — options-array overload via named expires_or_options (AOT).
$ok = setcookie(name: 'n', value: 'v', expires_or_options: ['path' => '/', 'httponly' => true]);
echo $ok ? "ok\n" : "bad\n";
