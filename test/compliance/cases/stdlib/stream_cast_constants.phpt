--TEST--
stdlib STREAM_CAST_AS_STREAM and STREAM_CAST_FOR_SELECT constants (ext/standard/streams.c, #11828)
--FILE--
<?php
echo defined('STREAM_CAST_AS_STREAM') ? STREAM_CAST_AS_STREAM : 'undef', "\n";
echo defined('STREAM_CAST_FOR_SELECT') ? STREAM_CAST_FOR_SELECT : 'undef', "\n";
--EXPECT--
0
3
