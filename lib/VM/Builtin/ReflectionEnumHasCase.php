<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionEnum::hasCase($name) — VM (#6930, php_reflection.c). */
final class ReflectionEnumHasCase extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasCase');
    }

    public function execute(Frame $frame): void
    {
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (exactly 1)
        $userArgCount = \count($frame->calledArgs) - 1;
        if (1 !== $userArgCount) {
            throw new \ArgumentCountError(\sprintf(
                'ReflectionEnum::hasCase() expects exactly 1 argument, %d given',
                $userArgCount
            ));
        }
        $receiver = ReflectionSupport::requireReflectionEnum($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $enumName = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $enumName);
        if (null === $entry || !$entry->isEnum) {
            throw new \LogicException('ReflectionEnum refers to unknown enum in this compiler build');
        }
        $caseName = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionEnum::hasCase() case', 1);
        $caseLc = \PHPCompiler\ClassConstName::key($caseName);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(isset($entry->enumCaseCanonicalNames[$caseLc]));
        }
    }
}
