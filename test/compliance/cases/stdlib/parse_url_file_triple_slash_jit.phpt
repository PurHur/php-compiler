--TEST--
stdlib parse_url('file:///') JIT empty host is valid — Zend scheme+path (#32085, ext/standard/url.c)
--FILE--
<?php
var_export(parse_url('file:///tmp/x'));
echo "\n";
var_export(parse_url('file:///'));
echo "\n";
var_export(parse_url('file://localhost/tmp/x'));
echo "\n";
var_export(parse_url('file://'));
echo "\n";
var_export(parse_url('http:///tmp/x'));
echo "\n";
echo parse_url('file:///tmp/x', PHP_URL_PATH), "\n";
echo var_export(parse_url('file:///tmp/x', PHP_URL_HOST), true), "\n";
var_export(parse_url('file:///c:/somedir/file.txt'));
echo "\n";
--EXPECT--
array (
  'scheme' => 'file',
  'path' => '/tmp/x',
)
array (
  'scheme' => 'file',
  'path' => '/',
)
array (
  'scheme' => 'file',
  'host' => 'localhost',
  'path' => '/tmp/x',
)
false
false
/tmp/x
NULL
array (
  'scheme' => 'file',
  'path' => 'c:/somedir/file.txt',
)
