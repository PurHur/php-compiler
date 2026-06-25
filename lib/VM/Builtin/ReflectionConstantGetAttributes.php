<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\AttributeRegistry;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::getAttributes() — VM read path (#4136). */
final class ReflectionConstantGetAttributes extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributes');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $constant = ReflectionSupport::constantNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionConstant refers to unknown class in this compiler build');
        }
        [$filter, $flags] = ReflectionSupport::getAttributesFilterArgs($frame, 'ReflectionConstant::getAttributes()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(
                AttributeRegistry::constantAttributes($frame, $entry, strtolower($constant), $filter, $flags)
            );
        }
    }
}
