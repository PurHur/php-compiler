<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM;
use PHPCompiler\VM\Context as VMContext;

/**
 * PHP 8.2+ compile-time E_DEPRECATED for `"${var}"` dollar-brace interpolation (#22001).
 *
 * php-src: Zend/zend_compile.c (zend_compile_encapsed_expr / T_DOLLAR_OPEN_CURLY_BRACES);
 * Zend/zend_language_parser.y — prefer `"{$var}"`.
 */
final class DollarBraceStringDeprecation
{
    public const MESSAGE = 'Using ${var} in strings is deprecated, use {$var} instead';

    public static function emitForSource(string $code, string $filename, VMContext $context): void
    {
        if (!CompilerVersion::supportsDollarBraceStringDeprecation()) {
            return;
        }
        if (!str_contains($code, '${')) {
            return;
        }
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return;
        }
        if (!\function_exists('token_get_all')) {
            return;
        }
        if (!\defined('T_DOLLAR_OPEN_CURLY_BRACES')) {
            return;
        }

        $frame = self::resolveEmitFrame($context, $filename);
        $tokens = token_get_all($code);
        foreach ($tokens as $token) {
            if (!\is_array($token) || \T_DOLLAR_OPEN_CURLY_BRACES !== $token[0]) {
                continue;
            }
            $line = isset($token[2]) ? (int) $token[2] : 0;
            $context->errors->internalDeprecated(
                self::MESSAGE,
                $context,
                $frame,
                $filename,
                $line
            );
        }
    }

    /**
     * User error handlers require a Frame (ErrorReporter::dispatchUserHandler). Prefer the
     * active eval/include caller so set_error_handler sees compile-time ${var} notices (#22001).
     */
    private static function resolveEmitFrame(VMContext $context, string $filename): Frame
    {
        $vm = VM::running();
        if ($vm instanceof VM) {
            $frame = $vm->builtinHandlerFrame();
            if (null !== $frame) {
                return $frame;
            }
            $frames = $context->runStackFrames();
            if ([] !== $frames) {
                return $frames[0];
            }
        }

        $block = new Block(null);
        $frame = new Frame(null, $block, null);
        $frame->vmContext = $context;
        $frame->scriptPath = $filename;

        return $frame;
    }
}
