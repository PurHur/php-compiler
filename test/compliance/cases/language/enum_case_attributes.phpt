--TEST--
Language: enum case attributes introspect via ReflectionEnumUnitCase (#5699, #22693, zend_attributes.c)
--FILE--
<?php
declare(strict_types=1);
enum E {
    #[\Deprecated(message: 'gone')]
    case A;
}
$ref = new ReflectionEnumUnitCase(E::class, 'A');
echo count($ref->getAttributes()) === 1 ? '1' : '0';
echo "\n";
echo $ref->getAttributes()[0]->getName();
--EXPECT--
1
Deprecated
