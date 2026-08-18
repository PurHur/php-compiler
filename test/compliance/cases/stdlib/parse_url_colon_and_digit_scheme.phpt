--TEST--
stdlib parse_url() digit-leading scheme / leading colon — Zend url.c (#32086)
--FILE--
<?php
var_export(parse_url(':'));
echo "\n";
var_export(parse_url(':80'));
echo "\n";
var_export(parse_url('0://host'));
echo "\n";
var_export(parse_url('1http://example.com'));
echo "\n";
var_export(parse_url('http://example.com'));
echo "\n";
var_export(parse_url('mailto:user@example.com'));
echo "\n";
var_export(parse_url('file://localhost/tmp/x'));
echo "\n";
var_export(parse_url('://host'));
echo "\n";
echo var_export(parse_url('0://host', PHP_URL_SCHEME), true), "\n";
echo var_export(parse_url('1http://example.com', PHP_URL_HOST), true), "\n";
echo var_export(parse_url(':', PHP_URL_PATH), true), "\n";
--EXPECT--
false
false
array (
  'scheme' => '0',
  'host' => 'host',
)
array (
  'scheme' => '1http',
  'host' => 'example.com',
)
array (
  'scheme' => 'http',
  'host' => 'example.com',
)
array (
  'scheme' => 'mailto',
  'path' => 'user@example.com',
)
array (
  'scheme' => 'file',
  'host' => 'localhost',
  'path' => '/tmp/x',
)
array (
  'path' => '://host',
)
'0'
'example.com'
false
