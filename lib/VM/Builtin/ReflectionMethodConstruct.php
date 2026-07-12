<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionMethod::__construct($class, $method) — VM (#3340). */
final class ReflectionMethodConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('ReflectionMethod::__construct() expects class and method name');
        }
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $entry = VmReflection::resolveClassFromArg($ctx, $frame->calledArgs[1]);
        $method = VmReflection::stringArg($frame->calledArgs[2], 'ReflectionMethod::__construct() method', 2);
        [$declEntry, $method] = ReflectionSupport::reflectionMethodFromClassAndMethod($ctx, $entry->name, $method);
        $receiver->getProperty(ReflectionSupport::PROP_REFLECTION_METHOD_CLASS)->string($declEntry->name);
        $receiver->getProperty(ReflectionSupport::PROP_REFLECTION_METHOD_FUNC)->string($method);
        $receiver->constructed = true;
    }
}
