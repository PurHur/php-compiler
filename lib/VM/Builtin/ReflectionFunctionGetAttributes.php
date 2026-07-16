<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\AttributeRegistry;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunctionAbstract::getAttributes() — VM read path (#19418). */
final class ReflectionFunctionGetAttributes extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributes');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        [$filter, $flags] = ReflectionSupport::getAttributesFilterArgs($frame, 'ReflectionFunction::getAttributes()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(AttributeRegistry::functionAttributes($frame, $receiver, $filter, $flags));
        }
    }
}
