<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::isSubclassOf() — VM (#6302, ext/reflection/php_reflection.c). */
final class ReflectionClassIsSubclassOf extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isSubclassOf');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::isSubclassOf() expects a class name');
        }
        [, $entry, $ctx] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        $parentName = ReflectionSupport::classNameFromReflectionClassArg(
            $frame->calledArgs[1],
            'isSubclassOf',
            'class'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmReflection::isSubclassOf($ctx, $entry->name, $parentName));
        }
    }
}
