<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/**
 * ReflectionAttribute::getTarget() — VM (#22044, ext/reflection/php_reflection.c).
 *
 * Returns the Attribute::TARGET_* bitmask for the declaration site where the
 * attribute was applied (not the Attribute class's allowed-target flags).
 */
final class ReflectionAttributeGetTarget extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTarget');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionAttribute($frame, $frame->calledArgs[0]);
        $targetVar = $receiver->getProperty(ReflectionSupport::PROP_ATTR_TARGET)->resolveIndirect();
        if (null !== $frame->returnVar) {
            if (Variable::TYPE_INTEGER === $targetVar->type) {
                $frame->returnVar->int($targetVar->toInt());
            } else {
                $frame->returnVar->int(0);
            }
        }
    }
}
