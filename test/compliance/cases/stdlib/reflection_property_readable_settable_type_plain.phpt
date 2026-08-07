--TEST--
ReflectionProperty::getType()/getSettableType() plain typed property (#9873, #28532)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

class C {
    public string $x = 'a';
}

$r = new ReflectionProperty(C::class, 'x');
echo (string) $r->getType(), "\n";
echo (string) $r->getSettableType(), "\n";
var_export($r->getType());
echo "\n";
--EXPECT--
string
string
\ReflectionNamedType::__set_state(array(
   'typeString' => 'string',
   'allowsNullFlag' => false,
   'typeName' => 'string',
   'typeBuiltin' => true,
   'typeMembers' => array (
  ),
))
