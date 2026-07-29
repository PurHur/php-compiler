--TEST--
hash_copy Reflection + named context (VM, issue #24566)
--FILE--
<?php
$r = new ReflectionFunction('hash_copy');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), PHP_EOL;
echo 'arity=', $r->getNumberOfParameters(), ' required=', $r->getNumberOfRequiredParameters(), PHP_EOL;
$c = hash_init('sha1');
hash_update($c, 'x');
echo hash_final(hash_copy(context: $c)), PHP_EOL;
$c2 = hash_init('sha1');
hash_update($c2, 'x');
echo hash_final(hash_copy($c2)), PHP_EOL;
--EXPECT--
context
arity=1 required=1
11f6ad8ec52a2984abaafd7c3b516503785c2072
11f6ad8ec52a2984abaafd7c3b516503785c2072
