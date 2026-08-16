<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for ftp_rawlist/mlsd via FtpListJitHelper (#31428).
 */
final class FtpListRuntime
{
    private const HELPER_PATH = '/ext/ftp/FtpListJitHelper.php';

    private const H = 'PHPCompiler\\ext\\ftp\\FtpListJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::rawlistArgv',
        self::H.'::mlsdArgv',
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_ftp_rawlist',
        '__compiler_ftp_mlsd',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_ftp_rawlist');
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
        self::implementRawlistBridge($context);
        self::implementMlsdBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementRawlistBridge(Context $context): void
    {
        $abiName = '__compiler_ftp_rawlist';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($htPtr, false, $i64, $strPtr, $i1)
            );
        $entry = $fn->appendBasicBlock('ftp_rawlist_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::rawlistArgv'),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $htPtr)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementMlsdBridge(Context $context): void
    {
        $abiName = '__compiler_ftp_mlsd';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($htPtr, false, $i64, $strPtr)
            );
        $entry = $fn->appendBasicBlock('ftp_mlsd_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::mlsdArgv'),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $htPtr)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#31428');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31428'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after FtpListRuntime link (#31428)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
