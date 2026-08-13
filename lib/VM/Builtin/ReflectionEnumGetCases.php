<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionEnum::getCases() — VM (#4121). */
final class ReflectionEnumGetCases extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getCases');
    }

    public function execute(Frame $frame): void
    {
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (0 args)
        $userArgCount = \count($frame->calledArgs) - 1;
        if (0 !== $userArgCount) {
            throw new \ArgumentCountError(\sprintf(
                'ReflectionEnum::getCases() expects exactly 0 arguments, %d given',
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
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(VmReflection::reflectionEnumCasesArray($ctx, $entry));
        }
    }
}
