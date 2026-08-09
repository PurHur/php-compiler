<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\Builtin\StringTriggerError;
use PHPCompiler\JIT\Call;
use PHPCompiler\VM\ErrorReporter;

/**
 * Emit E_USER_DEPRECATED when JIT/AOT lowers a call to a #[\Deprecated] function/method (#27331).
 *
 * php-src: Zend/zend_attributes.c / zend_execute.c — mirrors VM {@see \PHPCompiler\VM::emitCallDeprecationNotice}.
 * bin/jit.php still runs via VM (so notices appear there); thin AOT needs this lowering.
 */
final class DeprecatedCallGuard
{
    public static function registerCallee(Context $context, string $logicalName, Block $block): void
    {
        if (!CompilerVersion::supportsDeprecatedAttributeRuntimeNotices()) {
            return;
        }
        $meta = $block->deprecated;
        if (null === $meta || !$meta->emitsRuntimeNotice()) {
            return;
        }
        $context->deprecatedCalleeMeta[strtolower($logicalName)] = $meta;
    }

    public static function emitBeforeCall(Context $context, ?Call $toCall): void
    {
        if (!CompilerVersion::supportsDeprecatedAttributeRuntimeNotices()) {
            return;
        }
        if (!$toCall instanceof Call\Native) {
            return;
        }
        $lc = strtolower($toCall->name);
        if (!isset($context->deprecatedCalleeMeta[$lc])) {
            return;
        }
        $meta = $context->deprecatedCalleeMeta[$lc];
        if (!$meta->emitsRuntimeNotice()) {
            return;
        }
        if (str_contains($toCall->name, '::')) {
            [$class, $method] = explode('::', $toCall->name, 2);
            $message = $meta->formatMethod($class, $method);
        } else {
            $message = $meta->formatFunction($toCall->name);
        }
        self::emitUserDeprecated($context, $message, $context->callSiteLine);
    }

    /**
     * Class-constant fetch use-site notice (Zend zend_execute.c / #6962, #27331).
     */
    public static function emitClassConstFetch(
        Context $context,
        DeprecatedMetadata $meta,
        string $className,
        string $constName,
        int $line = 0
    ): void {
        if (!CompilerVersion::supportsDeprecatedAttributeRuntimeNotices()) {
            return;
        }
        if (!$meta->emitsRuntimeNotice()) {
            return;
        }
        self::emitUserDeprecated(
            $context,
            $meta->formatConstant($className, $constName),
            $line > 0 ? $line : $context->callSiteLine
        );
    }

    /**
     * Global constant fetch use-site notice (Zend zend_constants.c CONST_DEPRECATED, #29229).
     *
     * Mirrors VM {@see \PHPCompiler\VM::emitGlobalConstFetchDeprecation} for MCJIT/AOT.
     */
    public static function emitGlobalConstFetch(
        Context $context,
        DeprecatedMetadata $meta,
        string $constName,
        int $line = 0
    ): void {
        if (!CompilerVersion::supportsDeprecatedAttributeRuntimeNotices()) {
            return;
        }
        if (!$meta->emitsRuntimeNotice()) {
            return;
        }
        self::emitUserDeprecated(
            $context,
            $meta->formatGlobalConstant($constName),
            $line > 0 ? $line : $context->callSiteLine
        );
    }

    public static function emitUserDeprecated(Context $context, string $message, int $line): void
    {
        StringTriggerError::ensureLinked($context);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_USER_DEPRECATED, false),
            $emptyFile,
            $i32->constInt(max(0, $line), false)
        );
    }
}
