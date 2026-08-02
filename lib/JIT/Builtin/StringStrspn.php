<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for strspn()/strcspn() via StrspnJitHelper PHP (#14700, #24174).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringErrorLog #24094).
 * Replaces ~445-line LLVM in ext/standard/JitStrspn.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(strspn), PHP_FUNCTION(strcspn)
 */
final class StringStrspn
{
    private const HELPER_PATH = '/ext/standard/StrspnJitHelper.php';

    private const EXTENDED_HELPER = 'PHPCompiler\\ext\\standard\\StrspnJitHelper::extendedArgvInt';

    private const STRSPN_TWO_ARG = 'PHPCompiler\\ext\\standard\\StrspnJitHelper::strspnArgv';

    private const STRCSPN_TWO_ARG = 'PHPCompiler\\ext\\standard\\StrspnJitHelper::strcspnArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EXTENDED_HELPER,
        self::STRSPN_TWO_ARG,
        self::STRCSPN_TWO_ARG,
    ];

    /**
     * LLVM ABI names must not collide with libc — AOT exports of `strspn`/`strcspn`
     * interpose into libxcrypt and make crypt(3) return `*0` (#26861).
     *
     * @var list<string>
     */
    private const ABI_FUNCTIONS = [
        'phpc_strspn_extended',
        '__compiler_strspn',
        '__compiler_strcspn',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_strspn_extended');
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
        self::implementExtendedBridge($context);
        self::implementTwoArgBridge($context, '__compiler_strspn', self::STRSPN_TWO_ARG);
        self::implementTwoArgBridge($context, '__compiler_strcspn', self::STRCSPN_TWO_ARG);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementExtendedBridge(Context $context): void
    {
        $abiName = 'phpc_strspn_extended';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType(
            $i64,
            false,
            $strPtr,
            $strPtr,
            $i64,
            $i64,
            $i32,
            $i32
        );
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('strspn_extended_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::EXTENDED_HELPER),
            [
                $fn->getParam(0),
                $fn->getParam(1),
                $fn->getParam(2),
                $fn->getParam(3),
                $context->builder->zExt($fn->getParam(4), $i64),
                $context->builder->zExt($fn->getParam(5), $i64),
            ]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function implementTwoArgBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($sizeT, false, $charPtr, $charPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock($abiName.'_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $str = self::cstrToString($context, $fn->getParam(0));
        $mask = self::cstrToString($context, $fn->getParam(1));
        $raw = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            $str,
            $mask
        );
        $context->builder->returnValue(
            $context->builder->truncOrBitCast(
                JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64),
                $sizeT
            )
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $empty = $charPtr->constNull();
        $use = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $cstr, $empty),
            $empty,
            $cstr
        );
        $len = $context->builder->call($context->lookupFunction('strlen'), $use);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $use
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#24174');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24174'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringStrspn bridge (#14700)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
