<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\Lint\UnsupportedFeature;

/** ReflectionConstant::hasType() — globals always false (#21255). */
final class ReflectionConstantHasType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasType');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        if (ReflectionSupport::isGlobalReflectionConstant($receiver)) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            UnsupportedFeature::raise('reflection-constant-unknown-class');
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
