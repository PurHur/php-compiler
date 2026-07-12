<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM;

/** @internal php-src zend_errors.c — E_DEPRECATED from engine builtins (#18103–#18106). */
final class VmEngineBuiltinDeprecation
{
    public static function functionMessage(string $function): string
    {
        return 'Function '.$function.'() is deprecated';
    }

    public static function constantMessage(string $constant): string
    {
        return 'Constant '.$constant.' is deprecated';
    }

    public static function emitFunction(?Frame $frame, string $function): void
    {
        self::emit($frame, self::functionMessage($function));
    }

    public static function emitConstant(?Frame $frame, string $constant): void
    {
        self::emit($frame, self::constantMessage($constant));
    }

    public static function emitJitFunction(Context $context, string $function): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        JitBuiltinWarning::emitDeprecated($context, self::functionMessage($function));
    }

    public static function emitJitConstant(Context $context, string $constant): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        JitBuiltinWarning::emitDeprecated($context, self::constantMessage($constant));
    }

    private static function emit(?Frame $frame, string $message): void
    {
        $vm = VM::running();
        if (null === $vm) {
            return;
        }
        if (null === $frame) {
            $frame = $vm->builtinHandlerFrame();
            if (null === $frame) {
                $frames = $vm->context->runStackFrames();
                $frame = [] !== $frames ? $frames[0] : null;
            }
        }
        $vm->context->errors->internalDeprecated(
            $message,
            $vm->context,
            $frame
        );
    }
}
