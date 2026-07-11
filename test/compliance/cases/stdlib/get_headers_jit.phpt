--TEST--
stdlib get_headers() — JIT/AOT HTTP HEAD via GetHeadersJitHelper (#9212)
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
echo function_exists('get_headers') ? "fn\n" : "no-fn\n";
$h = get_headers('http://example.com');
echo ($h !== false && isset($h[0])) ? "ok\n" : "fail\n";
var_export(get_headers('file:///etc/hosts') === false);
echo "\n";
--EXPECT--
fn
ok
true
