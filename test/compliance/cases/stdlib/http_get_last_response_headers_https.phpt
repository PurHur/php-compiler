--TEST--
stdlib http_get_last_response_headers() after https wrapper fetch (issue #9752)
--SKIPIF--
<?php
if (!@file_get_contents('https://example.com')) {
    die('skip Network unavailable');
}
?>
--FILE--
<?php
if (function_exists('http_clear_last_response_headers')) {
    http_clear_last_response_headers();
}
$ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true]]);
@file_get_contents('https://example.com', false, $ctx);
$h = http_get_last_response_headers();
echo is_array($h) ? 'yes' : 'no', "\n";
echo is_array($h) && count($h) > 0 ? 'yes' : 'no', "\n";
echo is_array($h) && isset($h[0]) && str_starts_with((string) $h[0], 'HTTP/') ? 'yes' : 'no', "\n";
?>
--EXPECT--
yes
yes
yes
