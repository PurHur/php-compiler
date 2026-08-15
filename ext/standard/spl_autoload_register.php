<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\SplAutoloadCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * spl_autoload_register() — register class autoload callbacks on VM context (issue #1369).
 *
 * VM: string, array, and closure callables (#4744). JIT: compile-time function names, Class::method, closures (#1776).
 */
final class spl_autoload_register extends Internal
{
    public function __construct()
    {
        parent::__construct('spl_autoload_register');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/spl/php_spl.c — ArgumentCountError (#30575).
        $this->requireAtMostArgCount($frame, 'spl_autoload_register', 3);
        $argc = \count($frame->calledArgs);
        $ctx = VmReflection::requireContext($frame);
        $callback = null;
        if ($argc >= 1) {
            $callbackArg = $frame->calledArgs[0];
            if (EnumCaseSupport::isEnumCaseVariable($callbackArg)) {
                throw new \TypeError(SplAutoloadCallbackPolicy::invalidCallbackTypeError());
            }
            $callback = $callbackArg->resolveIndirect();
            if (Variable::TYPE_NULL === $callback->type) {
                $callback = null;
            } elseif (SplAutoloadCallbackPolicy::isPhpSrcInvalidCallbackType($callback->type)) {
                throw new \TypeError(SplAutoloadCallbackPolicy::invalidCallbackTypeError());
            } elseif (!SplAutoloadCallbackPolicy::isVmSupportedType($callback->type)) {
                throw new \LogicException(SplAutoloadCallbackPolicy::vmRejectionMessage());
            }
        }
        $throw = true;
        if (isset($frame->calledArgs[1])) {
            $throwArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $throwArg->type) {
                throw new \LogicException('spl_autoload_register() throw must be a boolean');
            }
            $throw = $throwArg->toBool();
        }
        $prepend = false;
        if (isset($frame->calledArgs[2])) {
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
        // Catchable ArgumentCountError (AOT/JIT) — #30575.
        if (!$this->requireAtMostJitArgCount($context, $args, 'spl_autoload_register', 3)) {
            return $context->constantFromBool(false);
        }
        if ($argc < 1) {
            throw new \LogicException(
                'spl_autoload_register() without a callback is not supported in this compiler build'
            );
        }
        if (null !== JitOperandTypeLabel::compileTimeEnumClassName($context, $args[0])) {
            throw new \TypeError(SplAutoloadCallbackPolicy::invalidCallbackTypeError());
        }
        if (SplAutoloadCallbackPolicy::isJitPhpSrcInvalidCallbackType($args[0])) {
            throw new \TypeError(SplAutoloadCallbackPolicy::invalidCallbackTypeError());
        }
        if (!SplAutoloadCallbackPolicy::isJitLowerable($args[0])) {
            throw new \LogicException(SplAutoloadCallbackPolicy::jitRejectionMessage());
        }
        if (null === $args[0]->closureCall) {
            $this->jitString($context, $args[0], 'spl_autoload_register() callback');
        }
        $prependArg = null;
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $prependArg = $args[2];
            if (JITVariable::TYPE_NATIVE_LONG !== $prependArg->type
                && JITVariable::TYPE_NATIVE_BOOL !== $prependArg->type
            ) {
                throw new \LogicException('spl_autoload_register() prepend must be a compile-time boolean');
            }
        }

        return JitSplAutoload::register($context, $args[0], $prependArg);
    }
}
