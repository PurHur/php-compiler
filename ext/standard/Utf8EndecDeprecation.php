<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\ErrorReporter;

/**
 * E_DEPRECATED on utf8_encode()/utf8_decode() call (php-src ext/standard/utf8.c; #18104, #29249).
 *
 * Zend since 8.2: "Function …() is deprecated since 8.2, visit the php.net documentation for various alternatives"
 */
final class Utf8EndecDeprecation
{
    public static function message(string $function): string
    {
        return 'Function '.$function.'() is deprecated since 8.2, visit the php.net documentation for various alternatives';
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

    /** JIT/AOT runtime via Utf8Latin1JitHelper bridge (#9912). */
    public static function recordCompiled(string $function): void
    {
        ErrorLastJitHelper::record(
            ErrorReporter::E_DEPRECATED,
            self::message($function),
            '',
            0
        );
        if (!ErrorSilenceJitHelper::shouldDisplayCliError(ErrorReporter::E_DEPRECATED)) {
            return;
        }
        TriggerErrorJitHelper::stderrPrintCliError(
            ErrorReporter::E_DEPRECATED,
            self::message($function),
            '',
            0
        );
    }
}
