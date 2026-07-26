--TEST--
hash_equals known_string/user_string named args (VM, issue #23205)
--FILE--
<?php
var_export(hash_equals(known_string: 'aa', user_string: 'aa'));
echo PHP_EOL;
var_export(hash_equals(known_string: 'aa', user_string: 'bb'));
echo PHP_EOL;
$rf = new ReflectionFunction('hash_equals');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), PHP_EOL;
echo $rf->getNumberOfParameters(), PHP_EOL;
--EXPECT--
true
false
known_string,user_string
2
