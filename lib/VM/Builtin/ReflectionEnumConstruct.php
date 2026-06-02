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
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionEnum::__construct() expects exactly 1 argument');
        }
        $ctx = VmReflection::requireContext($frame);
        $target = VmReflection::resolveClassFromArg($ctx, $frame->calledArgs[1]);
        if (!$target->isEnum) {
            throw new \LogicException('ReflectionEnum expects an enum class');
        }
        $receiver = ReflectionSupport::requireReflectionEnum($frame, $frame->calledArgs[0]);
        $receiver->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($target->name);
        $receiver->constructed = true;
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
