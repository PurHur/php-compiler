--TEST--
stdlib yaml_parse/yaml_emit basic mapping (#6275, PECL yaml / yaml.c)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsYaml()) {
    die('skip yaml withheld on reference profile (#6275)');
}
--FILE--
<?php
declare(strict_types=1);

$parsed = yaml_parse("foo: bar\n");
var_export($parsed);
echo "\n";
$emitted = yaml_emit(['a' => 1]);
$round = yaml_parse($emitted);
var_export($round);
echo "\n";
$bad = @yaml_parse('{');
var_export($bad);
echo "\n";
echo function_exists('yaml_parse') ? '1' : '0';
echo function_exists('yaml_parse_url') ? '1' : '0';
echo function_exists('yaml_emit') ? '1' : '0';
echo extension_loaded('yaml') ? '1' : '0';
echo "\n";
?>
--EXPECT--
array (
  'foo' => 'bar',
)
array (
  'a' => 1,
)
false
1111
