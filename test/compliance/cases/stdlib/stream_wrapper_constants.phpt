--TEST--
stdlib STREAM_REPORT_ERRORS / STREAM_CLIENT_* constants (php_stream_wrappers.h, #11886)
--FILE--
<?php
echo defined('STREAM_REPORT_ERRORS') ? STREAM_REPORT_ERRORS : 'undef', "\n";
echo defined('STREAM_CLIENT_ASYNC_CONNECT') ? STREAM_CLIENT_ASYNC_CONNECT : 'undef', "\n";
echo defined('STREAM_CLIENT_CONNECT') ? STREAM_CLIENT_CONNECT : 'undef', "\n";
echo defined('STREAM_CLIENT_PERSISTENT') ? STREAM_CLIENT_PERSISTENT : 'undef', "\n";
--EXPECT--
8
2
4
1
