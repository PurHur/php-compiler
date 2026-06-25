<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\AttributeRegistry;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::getAttributes() — VM read path (#1936). */
final class ReflectionMethodGetAttributes extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributes');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $method = ReflectionSupport::methodNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionMethod refers to unknown class in this compiler build');
        }
        [$filter, $flags] = ReflectionSupport::getAttributesFilterArgs($frame, 'ReflectionMethod::getAttributes()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(
                AttributeRegistry::methodAttributes($frame, $entry, strtolower($method), $filter, $flags)
            );
        }
    }
}
