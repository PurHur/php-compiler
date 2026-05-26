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
echo is_array($ctx) ? 'array' : 'other', "\n";
echo isset($ctx['http']) ? 'http' : 'no-http', "\n";
$http = $ctx['http'];
echo is_array($http) ? 'http-array' : 'http-other', "\n";
echo $http['timeout'] === 5 ? 'timeout' : 'bad-timeout', "\n";
echo $http['method'] === 'GET' ? 'method' : 'bad-method', "\n";
--EXPECT--
array
http
http-array
timeout
method
