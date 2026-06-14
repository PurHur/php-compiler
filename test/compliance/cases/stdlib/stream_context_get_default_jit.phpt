--TEST--
stdlib stream_context_get_default JIT/AOT (#6367)
--FILE--
<?php
$ctx1 = stream_context_get_default();
stream_context_set_default(['http' => ['timeout' => 7]]);
$ctx2 = stream_context_get_default();
echo $ctx1['__phpc_stream_context'] === $ctx2['__phpc_stream_context'] ? 'same' : 'diff';
echo "\n";
echo stream_context_get_options($ctx2)['http']['timeout'], "\n";
--EXPECT--
same
7
