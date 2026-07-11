--TEST--
stdlib stream_supports() — not advertised on PHP 8.2 reference profile (#11819)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('stream_supports') ? "fn-fail\n" : "fn-ok\n";
echo defined('STREAM_SUPPORT_LOCK') ? "lock-fail\n" : "lock-ok\n";
echo defined('STREAM_SUPPORT_SEEK') ? "seek-fail\n" : "seek-ok\n";
echo defined('STREAM_SUPPORT_TELL') ? "tell-fail\n" : "tell-ok\n";
echo defined('STREAM_SUPPORT_READ') ? "read-fail\n" : "read-ok\n";
echo defined('STREAM_SUPPORT_WRITE') ? "write-fail\n" : "write-ok\n";
echo function_exists('stream_supports_lock') ? "lock-fn-ok\n" : "lock-fn-fail\n";
--EXPECT--
fn-ok
lock-ok
seek-ok
tell-ok
read-ok
write-ok
lock-fn-ok
