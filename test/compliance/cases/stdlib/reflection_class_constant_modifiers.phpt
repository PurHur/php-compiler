--TEST--
Stdlib: ReflectionClassConstant::getModifiers() and IS_* constants (#17360)
--FILE--
<?php
class C {
    public const PUB = 1;
    protected const PROT = 2;
    private const PRIV = 3;
    public final const FIN = 4;
}
echo ReflectionClassConstant::IS_PUBLIC, "\n";
echo ReflectionClassConstant::IS_PROTECTED, "\n";
echo ReflectionClassConstant::IS_PRIVATE, "\n";
echo ReflectionClassConstant::IS_FINAL, "\n";
$pub = new ReflectionClassConstant(C::class, 'PUB');
$prot = new ReflectionClassConstant(C::class, 'PROT');
$priv = new ReflectionClassConstant(C::class, 'PRIV');
$fin = new ReflectionClassConstant(C::class, 'FIN');
echo $pub->getModifiers(), "\n";
echo $prot->getModifiers(), "\n";
echo $priv->getModifiers(), "\n";
echo $fin->getModifiers(), "\n";
--EXPECT--
1
2
4
32
1
2
4
33
