--TEST--
stdlib yaml_parse_url file:// + data:// + open failure (#22252, PECL yaml / yaml.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$path = sys_get_temp_dir().'/phpc_yaml_parse_url_'.getmypid().'.yml';
file_put_contents($path, "hello: world\n");

$fromFile = yaml_parse_url('file://'.$path);
var_export($fromFile);
echo "\n";

$fromData = yaml_parse_url('data://text/plain,foo: bar');
var_export($fromData);
echo "\n";

$missing = @yaml_parse_url('file:///no/such/yaml_parse_url_'.getmypid().'.yml');
var_export($missing);
echo "\n";

echo function_exists('yaml_parse_url') ? '1' : '0';
echo "\n";

@unlink($path);
?>
--EXPECT--
array (
  'hello' => 'world',
)
array (
  'foo' => 'bar',
)
false
1
