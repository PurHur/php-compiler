--TEST--
Stdlib: ReflectionClass::getConstants() / getConstant() — public constant map (VM, #6950)
--FILE--
<?php
class C {
    public const VERSION = '1.0';
    private const SECRET = 'hidden';
}

$rc = new ReflectionClass(C::class);
echo method_exists($rc, 'getConstants') ? 'getConstants:yes' : 'getConstants:missing', "\n";
echo method_exists($rc, 'getConstant') ? 'getConstant:yes' : 'getConstant:missing', "\n";
var_export($rc->getConstants());
echo "\n";
echo 'VERSION=', $rc->getConstant('VERSION'), "\n";
echo 'SECRET=', var_export($rc->getConstant('SECRET'), true), "\n";
echo 'MISSING=', var_export($rc->getConstant('MISSING'), true), "\n";
--EXPECT--
getConstants:yes
getConstant:yes
array (
  'VERSION' => '1.0',
  'SECRET' => 'hidden',
)
VERSION=1.0
SECRET='hidden'
MISSING=false
