<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::__construct($class) — VM (#1936). */
final class ReflectionClassConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::__construct() expects exactly 1 argument');
        }
        $ctx = VmReflection::requireContext($frame);
        $target = VmReflection::resolveClassFromArg($ctx, $frame->calledArgs[1]);
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $receiver->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($target->name);
        $receiver->constructed = true;
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
