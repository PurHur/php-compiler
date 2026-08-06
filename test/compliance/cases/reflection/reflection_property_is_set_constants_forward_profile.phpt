--TEST--
ReflectionProperty::IS_*_SET constants + getModifiers set bits (#28137, php_reflection.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C
{
    public private(set) string $name = 'n';
    public protected(set) int $age = 1;
    public string $plain = 'p';
}

foreach (['IS_PRIVATE_SET', 'IS_PROTECTED_SET', 'IS_PUBLIC_SET'] as $c) {
    echo $c, '=', defined('ReflectionProperty::' . $c) ? constant('ReflectionProperty::' . $c) : 'UNDEF', "\n";
}

$name = new ReflectionProperty(C::class, 'name');
$age = new ReflectionProperty(C::class, 'age');
$plain = new ReflectionProperty(C::class, 'plain');

echo 'name_isPrivateSet=', var_export($name->isPrivateSet(), true), "\n";
echo 'name_priv_bit=', ($name->getModifiers() & ReflectionProperty::IS_PRIVATE_SET) !== 0 ? '1' : '0', "\n";
echo 'name_mods=', $name->getModifiers(), "\n";

echo 'age_prot_bit=', ($age->getModifiers() & ReflectionProperty::IS_PROTECTED_SET) !== 0 ? '1' : '0', "\n";
echo 'age_priv_bit=', ($age->getModifiers() & ReflectionProperty::IS_PRIVATE_SET) !== 0 ? '1' : '0', "\n";

echo 'plain_pub_bit=', ($plain->getModifiers() & ReflectionProperty::IS_PUBLIC_SET) !== 0 ? '1' : '0', "\n";
echo 'plain_priv_bit=', ($plain->getModifiers() & ReflectionProperty::IS_PRIVATE_SET) !== 0 ? '1' : '0', "\n";
echo 'plain_mods=', $plain->getModifiers(), "\n";
--EXPECT--
IS_PRIVATE_SET=4096
IS_PROTECTED_SET=2048
IS_PUBLIC_SET=1024
name_isPrivateSet=true
name_priv_bit=1
name_mods=4129
age_prot_bit=1
age_priv_bit=0
plain_pub_bit=0
plain_priv_bit=0
plain_mods=1
