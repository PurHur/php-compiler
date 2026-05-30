<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::__construct($class, $name) — VM (#3354). */
final class ReflectionConstantConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('ReflectionConstant::__construct() expects class and constant name');
        }
        $ctx = VmReflection::requireContext($frame);
        $entry = VmReflection::resolveClassFromArg($ctx, $frame->calledArgs[1]);
        $constant = VmReflection::stringArg($frame->calledArgs[2], 'ReflectionConstant::__construct() name');
        if (null === VmReflection::findClassConstantKey($entry, $constant, $ctx)) {
            throw new \LogicException("Constant {$constant} does not exist on {$entry->name}");
        }
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        $receiver->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($entry->name);
        $receiver->getProperty(ReflectionSupport::PROP_CONSTANT_NAME)->string($constant);
        $receiver->constructed = true;
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
