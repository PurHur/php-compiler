--TEST--
AOT session_create_id() links PHP helper ABI (#27258)
--FILE--
<?php
$id = session_create_id();
echo (is_string($id) && strlen($id) > 0) ? "ok" : "bad";
echo "\n";
$prefixed = session_create_id('app-');
echo (is_string($prefixed) && strncmp($prefixed, 'app-', 4) === 0) ? "ok" : "bad";
echo "\n";
--EXPECT--
ok
ok
