<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::getAttributes() — VM read path (#3340). */
final class ReflectionParameterGetAttributes extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributes');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $all = ReflectionSupport::parameterAttributeEntries($ctx, $receiver);
        $filter = null;
        if (isset($frame->calledArgs[1])) {
            $filter = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionParameter::getAttributes() name', 1);
        }
        $entries = ReflectionSupport::filterEntriesByName($all, $filter);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(ReflectionSupport::attributesArrayFromEntries($frame, $entries));
        }
    }
}
