<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionMethod::__construct($objectOrMethod, $method = null) — VM (#3340, #22739). */
final class ReflectionMethodConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            ReflectionSupport::throwConstructArgumentCountError('ReflectionMethod', 1, $argc, 'at least');
        }
        if ($argc > 2) {
            ReflectionSupport::throwConstructArgumentCountError('ReflectionMethod', 2, $argc, 'at most');
        }
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (1 === $argc) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $arg->type) {
                throw new \ValueError(
                    'ReflectionMethod::__construct(): Argument #2 ($method) cannot be null when argument #1 ($objectOrMethod) is an object'
                );
            }
            $classMethod = VmReflection::stringArg(
                $frame->calledArgs[1],
                'ReflectionMethod::__construct() objectOrMethod',
                1
            );
            $sep = strpos($classMethod, '::');
            if (false === $sep) {
                ReflectionSupport::throwReflectionException(
                    'ReflectionMethod::__construct(): Argument #1 ($objectOrMethod) must be a valid method name'
                );
            }
            $className = substr($classMethod, 0, $sep);
            $method = substr($classMethod, $sep + 2);
            [$declEntry, $method] = ReflectionSupport::reflectionMethodFromClassAndMethod(
                $ctx,
                $className,
                $method
            );
        } else {
            $entry = VmReflection::resolveClassFromArg($ctx, $frame->calledArgs[1]);
            $method = VmReflection::stringArg($frame->calledArgs[2], 'ReflectionMethod::__construct() method', 2);
            [$declEntry, $method] = ReflectionSupport::reflectionMethodFromClassAndMethod(
                $ctx,
                $entry->name,
                $method
            );
        }
        $receiver->getProperty(ReflectionSupport::PROP_REFLECTION_METHOD_CLASS)->string($declEntry->name);
        $receiver->getProperty(ReflectionSupport::PROP_REFLECTION_METHOD_FUNC)->string($method);
        $receiver->constructed = true;
    }
}
