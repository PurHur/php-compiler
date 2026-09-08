<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\Lint\UnsupportedFeature;

/** ReflectionMethod::hasPrototype() — VM (#7262, ext/reflection/php_reflection.c). */
final class ReflectionMethodHasPrototype extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasPrototype');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $methodName = ReflectionSupport::methodNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            UnsupportedFeature::raise('reflection-method-unknown-class');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(
                ReflectionSupport::methodHasPrototype($ctx, $entry, strtolower($methodName))
            );
        }
    }
}
