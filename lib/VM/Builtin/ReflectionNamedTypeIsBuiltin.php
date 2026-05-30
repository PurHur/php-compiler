<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionNamedType::isBuiltin() — VM (#3355). */
final class ReflectionNamedTypeIsBuiltin extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isBuiltin');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionType($frame, $frame->calledArgs[0]);
        if (strtolower($receiver->class->name) !== ReflectionSupport::REFLECTION_NAMED_TYPE) {
            throw new \LogicException('Expected ReflectionNamedType instance');
        }
        $flag = $receiver->getProperty(ReflectionSupport::PROP_TYPE_BUILTIN)->resolveIndirect();
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(Variable::TYPE_BOOLEAN === $flag->type && $flag->toBool());
        }
    }
}
