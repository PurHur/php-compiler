--TEST--
stdlib session_destroy() JIT (issue #1182, #1056)
--FILE--
<?php
session_start();
$_SESSION['token'] = 'abc';
echo session_destroy() ? 'ok' : 'no', "\n";
echo session_destroy() ? 'again' : 'closed', "\n";
session_start();
echo isset($_SESSION['token']) ? 'present' : 'gone', "\n";
--EXPECT--
ok
closed
gone
