--TEST--
levenshtein Reflection cost params optional default 1 (#24791, ext/standard/string.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('levenshtein');
foreach ($r->getParameters() as $p) {
  $default = $p->isDefaultValueAvailable()
    ? var_export($p->getDefaultValue(), true)
    : ($p->isOptional() ? 'OPT' : 'REQ');
  echo $p->getName(), '=', $default, "\n";
}
echo 'required=', $r->getNumberOfRequiredParameters(), "\n";
echo 'dist=', levenshtein('abc', 'ab'), "\n";
echo 'named=', levenshtein(string1: 'abc', string2: 'ab'), "\n";
?>
--EXPECT--
string1=REQ
string2=REQ
insertion_cost=1
replacement_cost=1
deletion_cost=1
required=2
dist=1
named=1
