<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\AttributeRegistry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\Lint\UnsupportedFeature;

/** ReflectionEnumUnitCase::getAttributes() — VM (#3800). */
final class ReflectionEnumUnitCaseGetAttributes extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributes');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionEnumCase($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $enumName = ReflectionSupport::enumClassNameFromReflection($receiver);
        $caseName = ReflectionSupport::enumCaseNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $enumName);
        if (null === $entry || !$entry->isEnum) {
            UnsupportedFeature::raise('reflection-enum-unit-case-unknown');
        }
        [$filter, $flags] = ReflectionSupport::getAttributesFilterArgs($frame, 'ReflectionEnumUnitCase::getAttributes()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(
                AttributeRegistry::enumCaseAttributes($frame, $entry, \PHPCompiler\ClassConstName::key($caseName), $filter, $flags)
            );
        }
    }
}
