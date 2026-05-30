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
        $className = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionMethod::__construct() class');
        $method = VmReflection::stringArg($frame->calledArgs[2], 'ReflectionMethod::__construct() method');
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionMethod refers to unknown class in this compiler build');
        }
        $methodLc = strtolower($method);
        if (!isset($entry->methods[$methodLc])) {
            throw new \LogicException("Method {$method} does not exist on {$className}");
        }
        $receiver->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($entry->name);
        $receiver->getProperty(ReflectionSupport::PROP_METHOD_NAME)->string($method);
    }
}
