<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPCompiler\Lint\UnsupportedFeature;

/** ReflectionEnumUnitCase::getValue() — enum case object (#3800, #9537, #16178, php_reflection.c). */
final class ReflectionEnumUnitCaseGetValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getValue');
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
        $enum = EnumSupport::resolveRuntimeEnumClass($ctx, $entry);
        if (null !== $enum->backedType) {
            EnumSupport::ensureBackedEnumValuesUnique($enum);
        }
        $caseVar = EnumSupport::materializeCaseForCasesList($enum, $caseName);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($caseVar);
        }
    }
}
