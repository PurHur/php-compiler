<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::getReflectionConstant() — VM (#4136). */
final class ReflectionClassGetReflectionConstant extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getReflectionConstant');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::getReflectionConstant() expects a constant name');
        }
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        $constant = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionClass::getReflectionConstant() name');
        if (null === VmReflection::findClassConstantKey($entry, $constant, $ctx)) {
            throw new \LogicException("Constant {$constant} does not exist on {$className}");
        }
        $rcClass = $ctx->classes[ReflectionSupport::REFLECTION_CONSTANT] ?? null;
        if (null === $rcClass) {
            throw new \LogicException('ReflectionConstant is not registered in this compiler build');
        }
        $rc = new ObjectEntry($rcClass);
        $rc->constructed = true;
        $rc->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($entry->name);
        $rc->getProperty(ReflectionSupport::PROP_CONSTANT_NAME)->string($constant);
        if (null !== $frame->returnVar) {
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object($rc);
            $frame->returnVar->copyFrom($out);
        }
    }
}
