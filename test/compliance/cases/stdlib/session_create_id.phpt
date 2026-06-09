--TEST--
Stdlib: session_create_id() generates session id strings (#6002)
--FILE--
<?php
$id = session_create_id();
echo is_string($id) && strlen($id) > 0 ? 'idok' : 'idfail', "\n";
$prefixed = session_create_id('app-');
echo is_string($prefixed) && strncmp($prefixed, 'app-', 4) === 0 ? 'prefixed' : 'prefixfail', "\n";
echo (int) function_exists('session_create_id'), "\n";
--EXPECT--
idok
prefixed
1
