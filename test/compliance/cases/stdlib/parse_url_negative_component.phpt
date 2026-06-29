--TEST--
stdlib parse_url() negative component returns full array (#10645, ext/standard/url.c)
--FILE--
<?php
var_export(parse_url('http://example.com/path', -1));
echo "\n";
?>
--EXPECT--
array (
  'scheme' => 'http',
  'host' => 'example.com',
  'path' => '/path',
)
