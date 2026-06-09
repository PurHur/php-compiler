--TEST--
stdlib parse_url() JIT — scheme-relative //host/path URLs (#4226)
--FILE--
<?php
var_export(parse_url('//example.com/path'));
echo "\n";
echo parse_url('//example.com/path', PHP_URL_HOST), "\n";
echo parse_url('//example.com/path', PHP_URL_PATH), "\n";
--EXPECT--
array (
  'host' => 'example.com',
  'path' => '/path',
)
example.com
/path
