<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionAttribute::getName() — VM (#1936). */
final class ReflectionAttributeGetName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionAttribute::getName() called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== ReflectionSupport::REFLECTION_ATTRIBUTE) {
            throw new \LogicException('Expected ReflectionAttribute instance');
        }
        $nameVar = $obj->getProperty(ReflectionSupport::PROP_ATTR_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionAttribute missing name');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($nameVar->toString());
        }
    }
}
