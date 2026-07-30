--TEST--
str_split Reflection length default is 1 (#25044, ext/standard/string.stub.php)
--FILE--
<?php
$rf = new ReflectionFunction('str_split');
foreach ($rf->getParameters() as $p) {
  $default = $p->isDefaultValueAvailable()
    ? var_export($p->getDefaultValue(), true)
    : ($p->isOptional() ? 'OPT' : 'REQ');
  echo $p->getName(), '=', $default, "\n";
}
print_r(str_split('ab'));
?>
--EXPECT--
string=REQ
length=1
Array
(
    [0] => a
    [1] => b
)
