<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::__construct($objectOrClass) — VM (#1936, #28939). */
final class ReflectionClassConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs) - 1;
        if ($argc !== 1) {
            ReflectionSupport::throwConstructArgumentCountError('ReflectionClass', 1, $argc);
        }
        $ctx = VmReflection::requireContext($frame);
        $target = VmReflection::resolveClassFromArg($ctx, $frame->calledArgs[1]);
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $receiver->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($target->name);
        $receiver->constructed = true;
        // Do not touch returnVar: it may alias the `new ReflectionClass()` result slot (#1885, #4598).
    }
}
