--TEST--
stdlib hash_equals Reflection string,string→bool (#25470, ext/hash/hash.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('hash_equals');
echo 'ret=', (string) $r->getReturnType(), "\n";
foreach ($r->getParameters() as $p) {
    echo '  ', $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '?', ' opt=', $p->isOptional() ? 'Y' : 'N', "\n";
}
echo 'named=', hash_equals(known_string: 'aa', user_string: 'aa') ? 'true' : 'false', "\n";
?>
--EXPECT--
ret=bool
  known_string type=string opt=N
  user_string type=string opt=N
named=true
