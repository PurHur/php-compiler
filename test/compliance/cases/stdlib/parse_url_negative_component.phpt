--TEST--
stdlib parse_url() invalid component returns full array (#10645)
--FILE--
<?php
$url = 'http://example.com/path';
var_export(parse_url($url, -1));
echo "\n";
?>
--EXPECT--
array (
  'scheme' => 'http',
  'host' => 'example.com',
  'path' => '/path',
)
