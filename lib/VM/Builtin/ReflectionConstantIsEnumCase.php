<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::isEnumCase() — globals are never enum cases (#21255). */
final class ReflectionConstantIsEnumCase extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isEnumCase');
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
            throw new \LogicException('ReflectionConstant refers to unknown class in this compiler build');
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
