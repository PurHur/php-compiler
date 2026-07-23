--TEST--
ext/mysqli mysqli_poll / mysqli_reap_async_query registration (#22163)
--FILE--
<?php
echo function_exists('mysqli_poll') ? 'yes' : 'no', "\n";
echo function_exists('mysqli_reap_async_query') ? 'yes' : 'no', "\n";
echo method_exists('mysqli', 'poll') ? 'yes' : 'no', "\n";
echo method_exists('mysqli', 'reap_async_query') ? 'yes' : 'no', "\n";
echo defined('MYSQLI_ASYNC') ? (string) MYSQLI_ASYNC : 'no', "\n";
// No live MySQL in default CI — empty poll returns false without host mysqli_poll.
$read = null;
$error = null;
$reject = [];
$n = @mysqli_poll($read, $error, $reject, 0);
echo (false === $n) ? 'poll_false' : 'poll_other', "\n";
?>
--EXPECT--
yes
yes
yes
yes
128
poll_false
