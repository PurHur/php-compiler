--TEST--
ini_alter named option/value + Reflection (VM, issue #26465)
--FILE--
<?php
$old = ini_alter(option: 'display_errors', value: '0');
echo (is_string($old) || false === $old) ? "named_ok\n" : "named_bad\n";
$rf = new ReflectionFunction('ini_alter');
echo 'arity=', $rf->getNumberOfParameters(), PHP_EOL;
echo 'ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'NONE', PHP_EOL;
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'NONE', PHP_EOL;
}
--EXPECT--
named_ok
arity=2
ret=string|false
option:string
value:string|int|float|bool|null
