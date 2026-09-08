<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\Lint\UnsupportedFeature;

/** ReflectionClassConstant::isEnumCase() — VM (#9824, ext/reflection/php_reflection.c). */
final class ReflectionClassConstantIsEnumCase extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isEnumCase');
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
            $stored = $entry->constants[$key]->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($stored)) {
                $frame->returnVar->bool(true);

                return;
            }
            $frame->returnVar->bool(null !== EnumSupport::enumCaseNameForConstantMember($entry, $key));
        }
    }
}
