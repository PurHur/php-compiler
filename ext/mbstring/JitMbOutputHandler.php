<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbConvertEncodingRuntime;
use PHPCompiler\JIT\Builtin\MbHttpOutputRuntime;
use PHPCompiler\JIT\Builtin\MbInternalEncodingRuntime;
use PHPCompiler\JIT\Builtin\MbOutputHandlerRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_output_handler() (php-src ext/mbstring/mbstring.c; #20014).
 *
 * Compile-time fold for literal args; runtime via encoding globals + NestedJIT
 * {@see MbOutputHandlerJitHelper} (peer {@see JitMbGetInfo}).
 */
final class JitMbOutputHandler
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(sprintf(
                'mb_output_handler() expects exactly 2 arguments, %d given',
                \count($args)
            ));
        }

        $stringLit = JitStringArg::compileTimeLiteral($args[0]);
        $statusLit = self::compileTimeStatus($context, $args[1]);
        if (null !== $stringLit && null !== $statusLit) {
            return self::materializeString(
                $context,
                self::foldOutputHandler($context, $stringLit, $statusLit)
            );
        }

        return self::lowerRuntime($context, $args[0]);
    }

    private static function foldOutputHandler(Context $context, string $string, int $status): string
    {
        $httpOutput = MbstringAotFoldState::httpOutput($context) ?? (string) MbstringState::httpOutput();
        if (0 === strcasecmp($httpOutput, 'pass')) {
            return $string;
        }
        $from = MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding();
        if (0 === strcasecmp($from, $httpOutput)) {
            return $string;
        }
        $converted = VmMbstring::convertEncoding($string, $httpOutput, $from);
        if (false === $converted) {
            return $string;
        }

        return $converted;
    }

    private static function lowerRuntime(Context $context, JITVariable $stringArg): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbHttpOutputRuntime::ensureLinked($context);
        MbInternalEncodingRuntime::ensureLinked($context);
        MbOutputHandlerRuntime::ensureLinked($context);
        MbConvertEncodingRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_output_handler_runtime');

        $string = JitStringBuiltinArg::lower(
            $context,
            $stringArg,
            'mb_output_handler',
            0,
            'string'
        );

        $httpCode = $context->builder->load(MbHttpOutputRuntime::encodingCodeGlobal($context));
        $internalCode = $context->builder->load(MbInternalEncodingRuntime::encodingCodeGlobal($context));
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbOutputHandlerRuntime::convertHelper($context),
            [$string, $httpCode, $internalCode]
        );
        $resultStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);

        return self::materializeOwnedString($context, $resultStr);
    }

    private static function compileTimeStatus(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
            }
        }
        $constName = $arg->compileTimeConstantName ?? null;
        if (null !== $constName && null !== $context->runtime->vmContext) {
            $phpVar = $context->runtime->vmContext->constantFetch($constName);
            if (null !== $phpVar && \PHPCompiler\VM\Variable::TYPE_INTEGER === $phpVar->type) {
                return $phpVar->toInt();
            }
        }

        return null;
    }

    private static function materializeString(Context $context, string $str): Value
    {
        return self::materializeOwnedString(
            $context,
            $context->builder->load($context->constantStringFromString($str))
        );
    }

    private static function materializeOwnedString(Context $context, Value $resultStr): Value
    {
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }
}
