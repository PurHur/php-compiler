--TEST--
levenshtein Reflection cost params typed int (#25538, ext/standard/string.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('levenshtein');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '?', "\n";
foreach ($r->getParameters() as $p) {
  echo '  ', $p->getName(),
    ' type=', $p->hasType() ? (string) $p->getType() : '?',
    ' opt=', $p->isOptional() ? 'Y' : 'N';
  if ($p->isDefaultValueAvailable()) {
    echo ' def=', var_export($p->getDefaultValue(), true);
  }
  echo "\n";
}
echo 'named=', levenshtein(string1: 'abc', string2: 'ab', insertion_cost: 1, replacement_cost: 1, deletion_cost: 1), "\n";
?>
--EXPECT--
ret=int
  string1 type=string opt=N
  string2 type=string opt=N
  insertion_cost type=int opt=Y def=1
  replacement_cost type=int opt=Y def=1
  deletion_cost type=int opt=Y def=1
named=1
