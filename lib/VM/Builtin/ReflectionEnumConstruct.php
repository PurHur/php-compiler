<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionEnum::__construct($enum) — VM (#4121). */
final class ReflectionEnumConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs) - 1;
        if ($argc !== 1) {
            ReflectionSupport::throwConstructArgumentCountError('ReflectionEnum', 1, $argc);
        }
        $ctx = VmReflection::requireContext($frame);
        $target = VmReflection::resolveClassFromArg($ctx, $frame->calledArgs[1]);
        if (!$target->isEnum) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::classNotEnumMessage($target->name)
            );
        }
        $receiver = ReflectionSupport::requireReflectionEnum($frame, $frame->calledArgs[0]);
        $receiver->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($target->name);
        $receiver->constructed = true;
        // Do not touch returnVar: it may alias the `new ReflectionEnum()` result slot (#1885, #4598, #7370).
    }
}
