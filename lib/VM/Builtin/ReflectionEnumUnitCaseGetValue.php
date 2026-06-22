<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionEnumUnitCase::getValue() — enum case object for backed enums (#3800, #9537, php_reflection.c). */
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
        $enumName = ReflectionSupport::classNameFromReflection($receiver);
        $caseName = ReflectionSupport::enumCaseNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $enumName);
        if (null === $entry || !$entry->isEnum) {
            throw new \LogicException('ReflectionEnumUnitCase refers to unknown enum in this compiler build');
        }
        if (null === $entry->backedType) {
            throw new \Error('Cannot get value of a pure enum case');
        }
        $enum = EnumSupport::resolveRuntimeEnumClass($ctx, $entry);
        EnumSupport::ensureBackedEnumValuesUnique($enum);
        $caseVar = EnumSupport::materializeCaseForCasesList($enum, $caseName);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($caseVar);
        }
    }
}
