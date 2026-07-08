<?php
/**
 * Maintainer repro: ReflectionClassConstant::getModifiers() + IS_* (#17360).
 *
 * php-src: ext/reflection/php_reflection.c — reflection_class_constant_get_modifiers()
 */
declare(strict_types=1);

class C17360 {
    public const PUB = 1;
    protected const PROT = 2;
    private const PRIV = 3;
    public final const FIN = 4;
}

echo ReflectionClassConstant::IS_PUBLIC, "\n";
echo ReflectionClassConstant::IS_PROTECTED, "\n";
echo ReflectionClassConstant::IS_PRIVATE, "\n";
echo ReflectionClassConstant::IS_FINAL, "\n";

$pub = new ReflectionClassConstant(C17360::class, 'PUB');
$prot = new ReflectionClassConstant(C17360::class, 'PROT');
$priv = new ReflectionClassConstant(C17360::class, 'PRIV');
$fin = new ReflectionClassConstant(C17360::class, 'FIN');

echo $pub->getModifiers(), "\n";
echo $prot->getModifiers(), "\n";
echo $priv->getModifiers(), "\n";
echo $fin->getModifiers(), "\n";
