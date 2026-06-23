--TEST--
Language: ReflectionProperty::getValue()/setValue() static + instance (#4469, ext/reflection/php_reflection.c)
--FILE--
<?php
class C {
    public static int $stat = 10;
    public int $x = 1;
}

$rpS = new ReflectionProperty(C::class, 'stat');
$rpX = new ReflectionProperty(C::class, 'x');

var_dump($rpS->getValue());
var_dump($rpX->getValue(new C()));

try {
    $rpX->getValue();
} catch (TypeError $e) {
    echo 'missing obj: TypeError', "\n";
}

$c = new C();
$rpX->setValue($c, 99);
var_dump($c->x);

$rpS->setValue(55);
var_dump($rpS->getValue());
--EXPECT--
int(10)
int(1)
missing obj: TypeError
int(99)
int(55)
