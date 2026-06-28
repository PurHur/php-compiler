--TEST--
stdlib $http_response_header after http:// file_get_contents() (#11839, streams.c)
--FILE--
<?php
declare(strict_types=1);

@file_get_contents('http://example.com/');

echo isset($http_response_header) ? 'set' : 'unset', "\n";
echo is_array($http_response_header) ? 'array' : 'not-array', "\n";
echo isset($http_response_header[0]) && is_string($http_response_header[0]) ? 'status' : 'no-status', "\n";
echo str_starts_with($http_response_header[0], 'HTTP/') ? 'http-line' : 'bad-line', "\n";
--EXPECT--
set
array
status
http-line
