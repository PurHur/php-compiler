<?php
// #27599 — ReflectionProperty::{get,set}RawValue Reflection arity + named args (PROFILE=8.4).
class T
{
    public string $x = 'a';
}
$rp = new ReflectionProperty(T::class, 'x');
$o = new T();
$get = new ReflectionMethod(ReflectionProperty::class, 'getRawValue');
echo 'get arity=', $get->getNumberOfParameters(), ' ret=', ($get->hasReturnType() ? (string) $get->getReturnType() : 'NONE'), "\n";
foreach ($get->getParameters() as $p) {
    $type = $p->hasType() ? (string) $p->getType() : 'none';
    echo 'get ', $p->getName(), ' opt=', $p->isOptional() ? 'y' : 'n', ' type=', $type, "\n";
}
echo 'positional=', $rp->getRawValue($o), "\n";
echo 'named=', $rp->getRawValue(object: $o), "\n";
$set = new ReflectionMethod(ReflectionProperty::class, 'setRawValue');
echo 'set arity=', $set->getNumberOfParameters(), ' ret=', ($set->hasReturnType() ? (string) $set->getReturnType() : 'NONE'), "\n";
foreach ($set->getParameters() as $p) {
    $type = $p->hasType() ? (string) $p->getType() : 'none';
    echo 'set ', $p->getName(), ' opt=', $p->isOptional() ? 'y' : 'n', ' type=', $type, "\n";
}
$rp->setRawValue(object: $o, value: 'z');
echo 'after=', $rp->getRawValue($o), "\n";
