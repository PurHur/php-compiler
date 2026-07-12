--TEST--
AOT: stream_supports() phantom gates on 8.2 reference profile (#17697)
--FILE--
<?php
declare(strict_types=1);

echo defined('STREAM_SUPPORT_READ') ? "read-fail\n" : "read-ok\n";
echo defined('STREAM_SUPPORT_WRITE') ? "write-fail\n" : "write-ok\n";
echo function_exists('stream_supports') ? "fn-fail\n" : "fn-ok\n";
--EXPECT--
read-ok
write-ok
fn-ok
