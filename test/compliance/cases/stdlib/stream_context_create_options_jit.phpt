--TEST--
Stdlib: stream_context_create() http options (JIT, #1377, #2457)
--FILE--
<?php
$ctx = stream_context_create([
    'http' => [
        'timeout' => 5,
        'method' => 'GET',
    ],
]);
echo $ctx['http']['timeout'], "\n";
echo $ctx['http']['method'], "\n";
echo $ctx['__phpc_stream_context'] > 0 ? 'marker' : 'no_marker', "\n";
--EXPECT--
5
GET
marker
