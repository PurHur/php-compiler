--TEST--
stdlib get_headers() — libc HTTP HEAD via VmHttpFetchNative (issue #3309)
--SKIPIF--
<?php
if (!extension_loaded('ffi')) {
    die('skip ext/ffi required');
}
$headers = @get_headers('http://example.com');
if ($headers === false) {
    die('skip network unavailable for http://example.com');
}
?>
--FILE--
<?php
var_export(function_exists('get_headers'));
echo "\n";
$h = get_headers('http://example.com');
var_export($h !== false);
echo "\n";
var_export($h[0]);
echo "\n";
$h2 = get_headers('http://example.com', true);
var_export(isset($h2['Content-Type']));
echo "\n";
var_export(get_headers('https://example.com') === false);
echo "\n";
?>
--EXPECT--
true
true
'HTTP/1.1 200 OK'
true
true
