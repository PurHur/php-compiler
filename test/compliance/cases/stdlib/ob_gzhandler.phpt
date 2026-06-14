--TEST--
stdlib ob_gzhandler() — gzip output-buffer handler (issue #4655)
--FILE--
<?php
$_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip';
var_export(function_exists('ob_gzhandler'));
echo "\n";
ob_start();
ob_start('ob_gzhandler');
echo 'hello world';
ob_end_flush();
$out = ob_get_clean();
echo strlen($out), "\n";
echo substr($out, 0, 2) === "\x1f\x8b" ? 'gzip' : 'raw', "\n";
echo gzdecode($out), "\n";
--EXPECT--
true
31
gzip
hello world
