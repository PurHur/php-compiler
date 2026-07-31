<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionEnumUnitCase::__construct($enum, $case) — VM (#3800). */
final class ReflectionEnumUnitCaseConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs) - 1;
        if ($argc !== 2) {
            ReflectionSupport::throwConstructArgumentCountError('ReflectionEnumUnitCase', 2, $argc);
        }
        $ctx = VmReflection::requireContext($frame);
        $enumEntry = VmReflection::resolveClassFromArg($ctx, $frame->calledArgs[1]);
        if (!$enumEntry->isEnum) {
            throw new \LogicException('ReflectionEnumUnitCase expects an enum class');
        }
        $caseName = VmReflection::stringArg($frame->calledArgs[2], 'ReflectionEnumUnitCase::__construct() case', 2);
        $caseLc = \PHPCompiler\ClassConstName::key($caseName);
        if (!isset($enumEntry->enumCaseCanonicalNames[$caseLc])) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::enumCaseNotFoundMessage($enumEntry->name, $caseName)
            );
        }
        $receiver = ReflectionSupport::requireReflectionEnumCase($frame, $frame->calledArgs[0]);
        ReflectionSupport::initReflectionEnumCaseMetadata(
            $receiver,
            $enumEntry->name,
            $enumEntry->enumCaseCanonicalNames[$caseLc]
        );
        $receiver->constructed = true;
        // Do not touch returnVar: it may alias the `new ReflectionEnumUnitCase()` result slot (#1885, #5699).
    }
}
