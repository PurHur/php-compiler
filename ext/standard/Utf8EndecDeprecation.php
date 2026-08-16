<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM;

/**
 * E_DEPRECATED on utf8_encode()/utf8_decode() call (php-src ext/standard/utf8.c; #18104, #29249, #31176).
 *
 * Reference / PROFILE≤8.3 (ZEND_DEPRECATED_FUNCTION): "Function …() is deprecated"
 * PROFILE≥8.4 (#[\Deprecated(since: "8.2", message: "…")]): long since/php.net wording
 *
 * JIT/AOT: emit via {@see JitBuiltinWarning::emitDeprecated} in the caller module (peer strptime
 * {@see VmEngineBuiltinDeprecation::emitJitFunction}) — not inside Utf8Latin1JitHelper.
 */
final class Utf8EndecDeprecation
{
    public static function message(string $function): string
    {
        if (CompilerVersion::supportsDeprecatedAttributeRuntimeNotices()) {
            return 'Function '.$function.'() is deprecated since 8.2, visit the php.net documentation for various alternatives';
        }

        return 'Function '.$function.'() is deprecated';
    }

    public static function emitVm(Frame $frame, string $function): void
    {
        $vm = VM::running();
        if (null === $vm) {
            return;
        }
        $vm->context->errors->internalDeprecated(
            self::message($function),
            $vm->context,
            $frame
        );
    }

    /** JIT/AOT caller-module emission (peer #22771 strptime). */
    public static function emitJit(Context $context, string $function): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        JitBuiltinWarning::emitDeprecated($context, self::message($function));
    }
}
