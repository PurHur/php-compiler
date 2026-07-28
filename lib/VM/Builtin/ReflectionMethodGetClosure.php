<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionMethod::getClosure(?object $object = null) — VM (#6663, #24433, ext/reflection/php_reflection.c). */
final class ReflectionMethodGetClosure extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getClosure');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('ReflectionMethod::getClosure() expects a receiver');
        }
        // User arity excludes $this (php-src ReflectionMethod::getClosure, #24433).
        $userArgc = $argc - 1;
        if ($userArgc > 1) {
            throw new \ArgumentCountError(
                'ReflectionMethod::getClosure() expects at most 1 argument, '.$userArgc.' given'
            );
        }
        $reflection = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        [$declaring, $methodLc, $func] = ReflectionSupport::resolveReflectedMethod($ctx, $reflection);
        $methodName = $declaring->methodNames[$methodLc] ?? ReflectionSupport::methodNameFromReflection($reflection);
        $objectArg = $argc >= 2
            ? $frame->calledArgs[1]->resolveIndirect()
            : (new Variable())->null();

        $flags = ReflectionSupport::reflectedMethodCfgFlags($ctx, $reflection);
        if (($flags & CfgFunc::FLAG_STATIC) !== 0) {
            $state = ClosureState::fromWrappedFunc($func);
            $state->boundScopeClass = $declaring->name;
        } else {
            if (Variable::TYPE_NULL === $objectArg->type) {
                throw new \ValueError(
                    'ReflectionMethod::getClosure(): Argument #1 ($object) cannot be null for non-static methods'
                );
            }
            if (Variable::TYPE_OBJECT !== $objectArg->type) {
                throw new \TypeError(
                    'ReflectionMethod::getClosure(): Argument #1 ($object) must be of type ?object, '
                    .EnumCaseSupport::typeNameForVariable($objectArg).' given'
                );
            }
            if (!VmReflection::isInstanceOfObject($ctx, $objectArg, $declaring->name)) {
                ReflectionSupport::throwReflectionException(
                    'Given object is not an instance of the class this method was declared in'
                );
            }
            $boundThis = new Variable();
            $boundThis->copyFrom($objectArg);
            $state = ClosureState::fromMethodCallable($func, $boundThis, $methodName);
            $state->boundScopeClass = $objectArg->toObject()->class->name;
        }

        if (null === $frame->returnVar) {
            return;
        }
        $out = new Variable(Variable::TYPE_OBJECT);
        $out->object(ClosureSupport::wrapState($ctx, $state));
        $frame->returnVar->copyFrom($out);
    }
}
