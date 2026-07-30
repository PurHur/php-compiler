--TEST--
str_getcsv Reflection separator/enclosure/escape defaults (#24813, ext/standard/basic_functions.stub.php)
--FILE--
<?php
$rf = new ReflectionFunction('str_getcsv');
foreach ($rf->getParameters() as $p) {
  $default = $p->isDefaultValueAvailable()
    ? var_export($p->getDefaultValue(), true)
    : ($p->isOptional() ? 'OPT' : 'REQ');
  echo $p->getName(), '=', $default, "\n";
}
var_export(str_getcsv('a,b'));
echo "\n";
var_export(str_getcsv(string: 'a,b', separator: ',', enclosure: '"', escape: '\\'));
echo "\n";
?>
--EXPECT--
string=REQ
separator=','
enclosure='"'
escape='\\'
array (
  0 => 'a',
  1 => 'b',
)
array (
  0 => 'a',
  1 => 'b',
)
