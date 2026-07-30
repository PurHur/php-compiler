<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\CallUnpackHelper;
use PHPCompiler\JIT\Context;
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

            return null;
        }
        $encoded = VmJsonFormat::encodeExported($exported, $flags);
        if (false === $encoded) {
            if (VmJsonFlags::throwsOnError($flags)) {
                throw new \LogicException('json_encode() THROW path returned false');
            }

            return null;
        }

        return $context->builder->load($context->constantStringFromString($encoded));
    }
}
