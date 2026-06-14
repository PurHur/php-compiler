--TEST--
stdlib stream_context_get_default / set_default (#6367, ext/standard/streams.c)
--FILE--
<?php
echo function_exists('stream_context_get_default') ? '1' : '0';
echo function_exists('stream_context_set_default') ? '1' : '0';
echo "\n";

$ctx1 = stream_context_get_default();
$ctx2 = stream_context_get_default();
echo $ctx1['__phpc_stream_context'] === $ctx2['__phpc_stream_context'] ? 'same' : 'diff';
echo "\n";

stream_context_set_default(['http' => ['timeout' => 3]]);
$opts = stream_context_get_options(stream_context_get_default());
echo $opts['http']['timeout'], "\n";

stream_context_set_default(['http' => ['timeout' => 9]]);
$opts2 = stream_context_get_options(stream_context_get_default());
echo $opts2['http']['timeout'], "\n";

$ctx4 = stream_context_get_default(['http' => ['follow_location' => 0]]);
$opts3 = stream_context_get_options($ctx4);
echo $opts3['http']['follow_location'], "\n";
echo $opts3['http']['timeout'], "\n";
--EXPECT--
11
same
3
9
0
9
