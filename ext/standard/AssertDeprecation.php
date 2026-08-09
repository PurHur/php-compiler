<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM;

/**
 * PHP 8.3+ assert_options() / ASSERT_* E_DEPRECATED (php-src basic_functions.stub.php; #29209).
 *
 * Zend 8.3: "Function assert_options() is deprecated" (bare @deprecated).
 * Zend 8.4+: "Function assert_options() is deprecated since 8.3" (#[\Deprecated(since: '8.3')]).
 * ASSERT_* constants: "Constant ASSERT_* is deprecated" (no since) on both.
 */
final class AssertDeprecation
{
    /** @var list<string> */
    private const ASSERT_CONSTANTS = [
        'ASSERT_ACTIVE',
        'ASSERT_CALLBACK',
        'ASSERT_BAIL',
        'ASSERT_WARNING',
        'ASSERT_EXCEPTION',
    ];

    public static function assertOptionsDeprecatedMetadata(): ?DeprecatedMetadata
    {
        if (!CompilerVersion::supportsAssertOptionsDeprecation()) {
            return null;
        }
        // Stub gained since: '8.3' in PHP 8.4; 8.3 profile stays bare @deprecated.
        $since = version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=')
            ? '8.3'
            : null;

        return new DeprecatedMetadata(null, $since);
    }

    /** Bare @deprecated on ASSERT_* — no since/message (basic_functions.stub.php). */
    public static function assertConstantDeprecatedMetadata(): ?DeprecatedMetadata
    {
        if (!CompilerVersion::supportsAssertOptionsDeprecation()) {
            return null;
        }

        return new DeprecatedMetadata(null, null);
    }

    /**
     * Register ASSERT_* for CONST_FETCH use-site notices ({@see \PHPCompiler\VM} globalConstDeprecated).
     */
    public static function registerConstantDeprecations(\PHPCompiler\VM\Context $ctx): void
    {
        $meta = self::assertConstantDeprecatedMetadata();
        if (null === $meta) {
            return;
        }
        foreach (self::ASSERT_CONSTANTS as $name) {
            $ctx->globalConstDeprecated[strtolower($name)] = $meta;
        }
    }

    public static function emitAssertOptions(?Frame $frame): void
    {
        $meta = self::assertOptionsDeprecatedMetadata();
        if (null === $meta) {
            return;
        }
        $message = $meta->formatFunction('assert_options');
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
        $vm->context->errors->internalDeprecated($message, $vm->context, $frame);
    }

    public static function emitJitAssertOptions(Context $context): void
    {
        $meta = self::assertOptionsDeprecatedMetadata();
        if (null === $meta) {
            return;
        }
        JitBuiltinWarning::emitDeprecated($context, $meta->formatFunction('assert_options'));
    }
}
