<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\Lint\UnsupportedFeature;

/** ReflectionClassConstant::hasType() — VM (#17359, ext/reflection/php_reflection.c). */
final class ReflectionClassConstantHasType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasType');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionClassConstant($frame, $frame->calledArgs[0]);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            UnsupportedFeature::raise('reflection-class-constant-unknown-class');
        }
        $constant = ReflectionSupport::constantNameFromReflection($receiver);
        $key = VmReflection::findClassConstantKey($entry, $constant, $ctx);
        if (null === $key) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::constantNotFoundMessage($className, $constant)
            );
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(isset($entry->constDeclaredTypes[$key]));
        }
    }
}
