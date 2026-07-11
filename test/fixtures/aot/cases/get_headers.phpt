--TEST--
AOT get_headers() — HTTP HEAD via GetHeadersJitHelper (#9212, ext/standard/head.c)
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
$h = get_headers('http://example.com');
echo ($h !== false && isset($h[0])) ? "ok\n" : "fail\n";
$h2 = get_headers('http://example.com', true);
echo isset($h2['Content-Type']) ? "assoc\n" : "no-ctype\n";
var_export(get_headers('file:///etc/hosts') === false);
echo "\n";
--EXPECT--
ok
assoc
true
