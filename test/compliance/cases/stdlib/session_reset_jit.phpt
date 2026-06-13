--TEST--
stdlib session_reset() JIT — reload $_SESSION from storage (#6002)
--FILE--
<?php
session_start();
$_SESSION['k'] = 1;
echo session_reset() ? 'reset' : 'fail', "\n";
echo array_key_exists('k', $_SESSION) ? 'has_k' : 'no_k', "\n";
--EXPECT--
reset
no_k
