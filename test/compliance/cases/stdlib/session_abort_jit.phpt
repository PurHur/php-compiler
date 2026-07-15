--TEST--
stdlib session_abort() JIT — discard changes and end session (#6002, ext/session/session.c)
--FILE--
<?php
ob_start();
session_start();
$_SESSION['k'] = 1;
echo session_abort() ? 'aborted' : 'fail', "\n";
echo session_abort() ? 'again' : 'closed', "\n";
echo session_start() ? 'restarted' : 'closed', "\n";
--EXPECT--
aborted
closed
restarted
