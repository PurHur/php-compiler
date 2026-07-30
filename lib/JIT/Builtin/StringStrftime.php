<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_strftime via StrftimeJitHelper PHP (#9132, #25365).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer VarFetch #25328 / MathModf #22519).
 * Replaces gmtime/localtime/strftime libc LLVM; SSOT {@see \PHPCompiler\ext\standard\VmDate}.
 * php-src: ext/standard/datetime.c — PHP_FUNCTION(strftime), PHP_FUNCTION(gmstrftime)
 */
final class StringStrftime
{
    private const HELPER_PATH = '/ext/standard/StrftimeJitHelper.php';

    private const STRFTIME_HELPER = 'PHPCompiler\\ext\\standard\\StrftimeJitHelper::strftimeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRFTIME_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_strftime');
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
        self::implementStrftimeBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementStrftimeBridge(Context $context): void
    {
        $abiName = '__compiler_strftime';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');

        $ft = $context->context->functionType($strPtr, false, $strPtr, $i64, $i8);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('strftime_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::STRFTIME_HELPER),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $result, $strPtr)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25365');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25365'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_strftime');
        if (null === $fn) {
            throw new \LogicException('__compiler_strftime missing after StringStrftime bridge (#9132/#25365)');
        }
        $context->registerFunction('__compiler_strftime', $fn);
    }
}
