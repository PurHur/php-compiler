--TEST--
Stdlib: $_SESSION persists across session_write_close() / session_start() (#11527, ext/session/php_session.c)
--FILE--
<?php
session_start();
$_SESSION['k'] = 'v';
session_write_close();
session_start();
echo $_SESSION['k'] ?? 'NULL', "\n";
--EXPECT--
v
