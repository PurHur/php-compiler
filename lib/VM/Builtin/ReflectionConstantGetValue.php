<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::getValue() — VM (#3354). */
final class ReflectionConstantGetValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getValue');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionConstant refers to unknown class in this compiler build');
        }
        $constant = ReflectionSupport::constantNameFromReflection($receiver);
        $key = VmReflection::findClassConstantKey($entry, $constant, $ctx);
        if (null === $key) {
            throw new \LogicException("Constant {$constant} does not exist on {$className}");
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($entry->constants[$key]);
        }
    }
}
