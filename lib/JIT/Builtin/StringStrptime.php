<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_strptime via StrptimeJitHelper PHP (#9132, #25409).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringStrftime #25365 / VarFetch #25328).
 * Replaces libc strptime / struct tm LLVM; SSOT {@see \PHPCompiler\ext\standard\VmDate}.
 * php-src: ext/date/php_date.c — PHP_FUNCTION(strptime)
 */
final class StringStrptime
{
    private const HELPER_PATH = '/ext/standard/StrptimeJitHelper.php';

    private const STRPTIME_HELPER = 'PHPCompiler\\ext\\standard\\StrptimeJitHelper::strptimeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRPTIME_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_strptime');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementStrptimeBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementStrptimeBridge(Context $context): void
    {
        $abiName = '__compiler_strptime';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i32 = $context->getTypeFromString('int32');

        $ft = $context->context->functionType($voidTy, false, $strPtr, $strPtr, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('sp_bridge_entry');
        $nullOutBb = $fn->appendBasicBlock('sp_null_out');
        $bodyBb = $fn->appendBasicBlock('sp_body');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(2);
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $context->builder->branchIf($nullOut, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::STRPTIME_HELPER),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $htNull = JitNestedHelperCoerce::isHelperResultNull($context, $htRaw);
        $falseBb = $fn->appendBasicBlock('sp_false');
        $storeBb = $fn->appendBasicBlock('sp_store');
        $context->builder->branchIf($htNull, $falseBb, $storeBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($storeBb);
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $out,
            $ht
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25409');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25409'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_strptime');
        if (null === $fn) {
            throw new \LogicException('__compiler_strptime missing after StringStrptime bridge (#9132/#25409)');
        }
        $context->registerFunction('__compiler_strptime', $fn);
    }
}
