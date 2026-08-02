<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\StringJsonDecode;
use PHPCompiler\JIT\CallUnpackHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCfg\Operand;
use PHPLLVM\Value;

/**
 * Compile-time json_encode() for inline array literals — avoids deferred AOT stubs (#14040).
 *
 * php-src: ext/json/php_json.c — php_json_encode
 */
final class JitJsonEncodeCompileTime
{
    public static function tryEncode(
        Context $context,
        ?Block $block,
        ?Operand $operand,
        int $flags
    ): ?Value {
        if (null === $block || null === $operand) {
            return null;
        }
        $vmArray = CallUnpackHelper::tryCompileTimeArrayFromOperand($block, $operand);
        if (null === $vmArray) {
            return null;
        }
        try {
            $exported = VmJson::export($vmArray);
        } catch (VmJsonExportException $e) {
            if (
                VmJsonFlags::throwsOnError($flags)
                && !VmJsonFlags::partialOutputOnError($flags)
            ) {
                VmJson::throwExceptionPreservingLastError($e->errorCode);
            }
            if (VmJsonFlags::throwsOnError($flags)) {
                throw new \JsonException(VmJson::errorMsgForCode($e->errorCode), $e->errorCode);
            }
            // Soft-fail: bake false + sticky last_error (avoid AOT runtime INF crash, #26792).
            self::emitSetLastError($context, $e->errorCode);

            return self::emitFalse($context);
        }
        $encoded = VmJsonFormat::encodeExported($exported, $flags);
        $sticky = VmJson::lastError();
        if (false === $encoded) {
            if (VmJsonFlags::throwsOnError($flags)) {
                throw new \LogicException('json_encode() THROW path returned false');
            }
            self::emitSetLastError($context, $sticky);

            return self::emitFalse($context);
        }
        // PARTIAL substitutions leave sticky JSON_ERROR_* while still returning a string —
        // emit runtime set so last_error* observe it (#26792 / php-src json.c).
        if (0 !== $sticky) {
            self::emitSetLastError($context, $sticky);
        }

        return $context->builder->load($context->constantStringFromString($encoded));
    }

    /** Publish sticky/cleared JSON_ERROR_* into NestedJIT validate TU (#26792). */
    public static function emitSetLastError(Context $context, int $code): void
    {
        StringJsonDecode::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction('__compiler_json_set_last_error'),
            $context->getTypeFromString('int64')->constInt($code, false)
        );
    }

    /** @return Value __value__* bool false */
    private static function emitFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }
}
