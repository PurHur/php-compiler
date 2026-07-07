<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::isInstance() — VM (#6302, ext/reflection/php_reflection.c). */
final class ReflectionClassIsInstance extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isInstance');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::isInstance() expects an object');
        }
        [, $entry, $ctx] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        $object = $frame->calledArgs[1]->resolveIndirect();
        $matches = false;
        if (Variable::TYPE_OBJECT === $object->type || Variable::TYPE_ENUM_CASE === $object->type) {
            $matches = VmReflection::isInstanceOfObject($ctx, $object, $entry->name);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($matches);
        }
    }
}
