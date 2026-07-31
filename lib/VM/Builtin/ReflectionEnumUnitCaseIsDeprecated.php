<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionEnumUnitCase::isDeprecated() — VM (#9864, ext/reflection/php_reflection.c). */
final class ReflectionEnumUnitCaseIsDeprecated extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isDeprecated');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionEnumCase($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $enumName = ReflectionSupport::enumClassNameFromReflection($receiver);
        $caseName = ReflectionSupport::enumCaseNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $enumName);
        if (null === $entry || !$entry->isEnum) {
            throw new \LogicException('ReflectionEnumUnitCase refers to unknown enum in this compiler build');
        }
        if (null !== $frame->returnVar) {
            $meta = $entry->constDeprecated[\PHPCompiler\ClassConstName::key($caseName)] ?? null;
            $frame->returnVar->bool(
                null !== $meta && $meta->isDeprecatedForReflection()
            );
        }
    }
}
