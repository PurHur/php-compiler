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
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $method = ReflectionSupport::methodNameFromReflection($receiver);
        $position = ReflectionSupport::paramPositionFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionParameter refers to unknown class in this compiler build');
        }
        $methodLc = strtolower($method);
        $params = $entry->methodParameterMetadata[$methodLc] ?? [];
        $paramMeta = $params[$position] ?? null;
        $all = null !== $paramMeta ? $paramMeta->attributes : [];
        $filter = null;
        if (isset($frame->calledArgs[1])) {
            $filter = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionParameter::getAttributes() name');
        }
        $entries = ReflectionSupport::filterEntriesByName($all, $filter);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(ReflectionSupport::attributesArrayFromEntries($frame, $entries));
        }
    }
}
