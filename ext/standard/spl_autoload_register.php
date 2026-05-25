<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\SplAutoloadCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * spl_autoload_register() — register class autoload callbacks on VM context (issue #1369).
 *
 * VM: string callables. JIT: compile-time string user-function names (#1776).
 */
final class spl_autoload_register extends Internal
{
    public function __construct()
    {
        parent::__construct('spl_autoload_register');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 3) {
            throw new \LogicException(
                'spl_autoload_register() accepts zero to three arguments in this compiler build'
            );
        }
        $ctx = VmReflection::requireContext($frame);
        $callback = null;
        if ($argc >= 1) {
            $callback = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL === $callback->type) {
                $callback = null;
            } elseif (!SplAutoloadCallbackPolicy::isVmSupportedType($callback->type)) {
                throw new \LogicException(SplAutoloadCallbackPolicy::vmRejectionMessage());
            }
        }
        $throw = true;
        if ($argc >= 2) {
            $throwArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $throwArg->type) {
                throw new \LogicException('spl_autoload_register() throw must be a boolean');
            }
            $throw = $throwArg->toBool();
        }
        $prepend = false;
        if ($argc >= 3) {
            $prependArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $prependArg->type) {
                throw new \LogicException('spl_autoload_register() prepend must be a boolean');
            }
            $prepend = $prependArg->toBool();
        }
        try {
            $ok = VmSplAutoload::register($ctx, $callback, $prepend);
        } catch (\LogicException $e) {
            if ($throw) {
                throw $e;
            }
            $ok = false;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 3) {
            throw new \LogicException(
                'spl_autoload_register() accepts zero to three arguments in this compiler build'
            );
        }
        if ($argc < 1) {
            throw new \LogicException(
                'spl_autoload_register() without a callback is not supported in this compiler build'
            );
        }
        if (!SplAutoloadCallbackPolicy::isJitLowerable($args[0])) {
            throw new \LogicException(SplAutoloadCallbackPolicy::jitRejectionMessage());
        }
        $this->jitString($context, $args[0], 'spl_autoload_register() callback');
        $prependArg = 3 === $argc ? $args[2] : null;
        if (null !== $prependArg
            && JITVariable::TYPE_NATIVE_LONG !== $prependArg->type
            && JITVariable::TYPE_NATIVE_BOOL !== $prependArg->type
        ) {
            throw new \LogicException('spl_autoload_register() prepend must be a compile-time boolean');
        }

        return JitSplAutoload::register($context, $args[0], $prependArg);
    }
}
