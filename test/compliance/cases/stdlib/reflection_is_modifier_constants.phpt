--TEST--
Stdlib: Reflection IS_ modifier class constants (#22128, php_reflection.stub.php)
--FILE--
<?php
echo ReflectionMethod::IS_ABSTRACT, "\n";
echo ReflectionMethod::IS_PUBLIC, "\n";
echo ReflectionMethod::IS_PROTECTED, "\n";
echo ReflectionMethod::IS_PRIVATE, "\n";
echo ReflectionMethod::IS_STATIC, "\n";
echo ReflectionMethod::IS_FINAL, "\n";
echo ReflectionFunction::IS_DEPRECATED, "\n";
echo ReflectionProperty::IS_READONLY, "\n";
echo ReflectionProperty::IS_PUBLIC, "\n";
echo ReflectionProperty::IS_STATIC, "\n";
echo ReflectionClass::IS_IMPLICIT_ABSTRACT, "\n";
echo ReflectionClass::IS_EXPLICIT_ABSTRACT, "\n";
echo ReflectionClass::IS_FINAL, "\n";
echo ReflectionClass::IS_READONLY, "\n";
$rc = new ReflectionClass(ReflectionClass::class);
echo $rc->hasConstant('IS_READONLY') ? 'Y' : 'N', "\n";
echo $rc->getConstant('IS_READONLY'), "\n";
--EXPECT--
64
1
2
4
16
32
2048
128
1
16
16
64
32
65536
Y
65536
