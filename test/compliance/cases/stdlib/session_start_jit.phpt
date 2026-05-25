--TEST--
stdlib session_start() JIT (issue #1882)
--FILE--
<?php
echo session_start() ? 'started' : 'fail', "\n";
echo session_start() ? 'again' : 'closed', "\n";
session_write_close();
echo session_start() ? 'reopened' : 'no', "\n";
--EXPECT--
started
closed
reopened
