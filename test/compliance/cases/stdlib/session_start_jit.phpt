--TEST--
stdlib session_start() JIT (issue #1882)
--FILE--
<?php
echo session_start() ? 'started' : 'fail', "\n";
echo session_start() ? 'again' : 'closed', "\n";
session_write_close();
echo session_start() ? 'reopened' : 'no', "\n";
--EXPECT--
PHP Notice:  session_start(): Ignoring session_start() because a session is already active in - on line 3
started
again
reopened
