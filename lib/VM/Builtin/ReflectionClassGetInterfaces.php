<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::getInterfaces() — VM (#22170, ext/reflection/php_reflection.c). */
final class ReflectionClassGetInterfaces extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getInterfaces');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionClass::getInterfaces() expects an object');
        }
        $obj = $receiver->toObject();
        self::requireReflectionClassOrEnum($obj);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($obj);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(
                VmReflection::reflectionClassInterfacesMap($ctx, $entry)
            );
        }
    }

    private static function requireReflectionClassOrEnum(ObjectEntry $obj): void
    {
        if (!ReflectionSupport::isReflectionClassObject($obj)) {
            throw new \LogicException('Expected ReflectionClass instance');
        }
    }
}
