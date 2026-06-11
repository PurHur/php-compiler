--TEST--
AOT: stream_context_set_options / get_options (#6517)
--FILE--
<?php
$ctx = stream_context_create(['http' => ['timeout' => 5]]);
stream_context_set_options($ctx, ['http' => ['follow_location' => 0, 'timeout' => 10]]);
$opts = stream_context_get_options($ctx);
echo $opts['http']['timeout'], "\n";
echo $opts['http']['follow_location'], "\n";
--EXPECT--
10
0
