<?php
// Issue #23360 AOT — Zend-style named expires_or_options binds for setcookie/setrawcookie.
// Legacy $expires rejection is covered on VM/JIT (AOT NamedArgs rejects unknown names at compile time).
$a = setcookie(name: 'n', value: 'v', expires_or_options: 0);
$b = setrawcookie(name: 'n', value: 'v', expires_or_options: 0);
echo 'setcookie:', $a ? '1' : '0', PHP_EOL;
echo 'setrawcookie:', $b ? '1' : '0', PHP_EOL;
?>
