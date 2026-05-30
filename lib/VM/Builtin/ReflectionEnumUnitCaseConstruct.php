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
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('ReflectionEnumUnitCase::__construct() expects exactly 2 arguments');
        }
        $ctx = VmReflection::requireContext($frame);
        $enumEntry = VmReflection::resolveClassFromArg($ctx, $frame->calledArgs[1]);
        if (!$enumEntry->isEnum) {
            throw new \LogicException('ReflectionEnumUnitCase expects an enum class');
        }
        $caseName = VmReflection::stringArg($frame->calledArgs[2], 'ReflectionEnumUnitCase::__construct() case');
        $caseLc = strtolower($caseName);
        if (!isset($enumEntry->enumCaseCanonicalNames[$caseLc])) {
            throw new \LogicException('Enum '.$enumEntry->name.' has no case named '.$caseName);
        }
        $receiver = ReflectionSupport::requireReflectionEnumUnitCase($frame, $frame->calledArgs[0]);
        $receiver->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($enumEntry->name);
        $receiver->getProperty(ReflectionSupport::PROP_ENUM_CASE_NAME)->string(
            $enumEntry->enumCaseCanonicalNames[$caseLc]
        );
        $receiver->constructed = true;
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
