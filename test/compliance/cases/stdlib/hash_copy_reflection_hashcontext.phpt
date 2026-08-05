--TEST--
stdlib hash_copy Reflection HashContext stubs (#27745, ext/hash/hash.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('hash_copy');
$p = $r->getParameters()[0];
echo 'context=', $p->hasType() ? (string) $p->getType() : '(none)', PHP_EOL;
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', PHP_EOL;
$c = hash_init('sha1');
hash_update($c, 'ab');
$copy = hash_copy(context: $c);
hash_update($c, 'c');
echo hash_final($copy), PHP_EOL;
echo hash_final($c), PHP_EOL;
echo 'runtime=', get_debug_type($copy), PHP_EOL;
?>
--EXPECT--
context=HashContext
return=HashContext
da23614e02469a0d7c7bd1bdab5c9c474b1904dc
a9993e364706816aba3e25717850c26c9cd0d89d
runtime=HashContext
