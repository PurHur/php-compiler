--TEST--
ReflectionProperty::getReadableType()/getSettableType() plain typed property (#9873)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

class C {
    public string $x = 'a';
}

$r = new ReflectionProperty(C::class, 'x');
echo (string) $r->getReadableType(), "\n";
echo (string) $r->getSettableType(), "\n";
var_export($r->getReadableType());
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
