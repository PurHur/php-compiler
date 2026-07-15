--TEST--
stdlib session_start() JIT (issue #1882)
--FILE--
<?php
ob_start();
echo session_start() ? 'started' : 'fail', "\n";
echo session_start() ? 'again' : 'closed', "\n";
session_write_close();
echo session_start() ? 'reopened' : 'no', "\n";
--EXPECT--
PHP Notice:  session_start(): Ignoring session_start() because a session is already active in - on line 4
started
again
reopened
