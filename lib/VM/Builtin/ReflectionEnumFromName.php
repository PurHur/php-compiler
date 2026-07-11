<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionEnum::fromName($enum, $case) — static factory (php-src ext/reflection/php_reflection.c; #16877). */
final class ReflectionEnumFromName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fromName');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'ReflectionEnum::fromName() expects exactly 2 arguments, '
                .\count($frame->calledArgs).' given'
            );
        }
        $ctx = VmReflection::requireContext($frame);
        $enumName = VmReflection::stringArg($frame->calledArgs[0], 'ReflectionEnum::fromName() enum', 0);
        $caseName = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionEnum::fromName() case', 1);
        if (null === VmReflection::resolveClassEntry($ctx, $enumName)) {
            $ctx->autoloadClass($enumName);
        }
        $entry = VmReflection::resolveClassEntry($ctx, $enumName);
        if (null === $entry || !$entry->isEnum) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::classNotFoundMessage($enumName)
            );
        }
        $obj = VmReflection::newReflectionEnumCase($ctx, $entry, $caseName);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($obj);
        }
    }
}
