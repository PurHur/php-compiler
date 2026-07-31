<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionEnumBackedCase::__construct($enum, $case) — VM (#5675). */
final class ReflectionEnumBackedCaseConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs) - 1;
        if ($argc !== 2) {
            ReflectionSupport::throwConstructArgumentCountError('ReflectionEnumBackedCase', 2, $argc);
        }
        $ctx = VmReflection::requireContext($frame);
        $enumEntry = VmReflection::resolveClassFromArg($ctx, $frame->calledArgs[1]);
        if (!$enumEntry->isEnum) {
            throw new \LogicException('ReflectionEnumBackedCase expects an enum class');
        }
        if (null === $enumEntry->backedType) {
            throw new \LogicException('ReflectionEnumBackedCase expects a backed enum class');
        }
        $caseName = VmReflection::stringArg($frame->calledArgs[2], 'ReflectionEnumBackedCase::__construct() case', 2);
        $caseLc = \PHPCompiler\ClassConstName::key($caseName);
        if (!isset($enumEntry->enumCaseCanonicalNames[$caseLc])) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::enumCaseNotFoundMessage($enumEntry->name, $caseName)
            );
        }
        $receiver = ReflectionSupport::requireReflectionEnumBackedCase($frame, $frame->calledArgs[0]);
        ReflectionSupport::initReflectionEnumCaseMetadata(
            $receiver,
            $enumEntry->name,
            $enumEntry->enumCaseCanonicalNames[$caseLc]
        );
        $receiver->constructed = true;
    }
}
