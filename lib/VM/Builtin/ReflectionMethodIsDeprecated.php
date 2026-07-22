<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::isDeprecated() — VM (#6803, ext/reflection/php_reflection.c). */
final class ReflectionMethodIsDeprecated extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isDeprecated');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $methodName = ReflectionSupport::methodNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionMethod refers to unknown class in this compiler build');
        }
        $methodLc = strtolower($methodName);
        if (null === $frame->returnVar) {
            return;
        }
        if (!CompilerVersion::supportsReflectionFunctionIsDeprecated()) {
            $frame->returnVar->bool(false);

            return;
        }
        $meta = $entry->methodDeprecated[$methodLc] ?? null;
        $frame->returnVar->bool(
            null !== $meta && $meta->isDeprecatedForReflection()
        );
    }
}
