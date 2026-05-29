<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::__construct($function) — VM (#3354, #3355). */
final class ReflectionFunctionConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionFunction::__construct() expects a function name');
        }
        $ctx = VmReflection::requireContext($frame);
        $name = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionFunction::__construct() name');
        ReflectionSupport::resolveUserFunction($ctx, $name);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        $receiver->getProperty(ReflectionSupport::PROP_FUNC_NAME)->string($name);
        $receiver->constructed = true;
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
