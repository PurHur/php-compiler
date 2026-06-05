<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::isUninitializedLazyObject() — VM (#6054, #6068, ext/reflection/php_reflection.c). */
final class ReflectionClassIsUninitializedLazyObject extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isUninitializedLazyObject');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::isUninitializedLazyObject() expects an object');
        }
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $objectVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            throw new \TypeError(
                'ReflectionClass::isUninitializedLazyObject(): Argument #1 ($object) must be of type object'
            );
        }
        if (!VmReflection::isInstanceOfObject($ctx, $objectVar, $className)) {
            throw new \TypeError(sprintf(
                'ReflectionClass::isUninitializedLazyObject(): Argument #1 ($object) must be an instance of %s, %s given',
                $className,
                $objectVar->toObject()->class->name
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(LazyObjectSupport::isUninitializedLazyObject($objectVar->toObject()));
    }
}
