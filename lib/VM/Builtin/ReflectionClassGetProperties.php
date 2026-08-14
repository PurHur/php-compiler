<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
/** ReflectionClass::getProperties() — VM (#3815). */
final class ReflectionClassGetProperties extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getProperties');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionClass_getProperties — optional ?int $filter (at most 1) (#31033)
        $this->requireUserArgCountRange($frame, 'ReflectionClass::getProperties', 0, 1);
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        $filter = VmReflection::optionalReflectionFilterArg($frame, 1, 'ReflectionClass::getProperties');
        if (null !== $frame->returnVar) {
            $instance = ReflectionSupport::objectTargetFromReflectionObject($receiver);
            $frame->returnVar->copyFrom(
                VmReflection::reflectionPropertiesArray($ctx, $entry, $entry->name, $filter, $instance)
            );
        }
    }
}
