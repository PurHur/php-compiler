--TEST--
Stdlib: session_abort() discards changes and ends session (#6002)
--FILE--
<?php
ob_start();
session_start();
$_SESSION['k'] = 1;
echo session_abort() ? 'aborted' : 'fail', "\n";
echo session_start() ? 'restarted' : 'closed', "\n";
echo (int) function_exists('session_abort'), "\n";
--EXPECT--
aborted
restarted
1
