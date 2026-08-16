<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for ftp_mkdir/delete/rename/rmdir via FtpMutateJitHelper (#31427).
 */
final class FtpMutateRuntime
{
    private const HELPER_PATH = '/ext/ftp/FtpMutateJitHelper.php';

    private const H = 'PHPCompiler\\ext\\ftp\\FtpMutateJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::mkdirArgv',
        self::H.'::deleteArgv',
        self::H.'::renameArgv',
        self::H.'::rmdirArgv',
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_ftp_mkdir',
        '__compiler_ftp_delete',
        '__compiler_ftp_rename',
        '__compiler_ftp_rmdir',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_ftp_mkdir');
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
        self::implementMkdirBridge($context);
        self::implementDeleteBridge($context);
        self::implementRenameBridge($context);
        self::implementRmdirBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementMkdirBridge(Context $context): void
    {
        $abiName = '__compiler_ftp_mkdir';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $i64, $strPtr)
            );
        $entry = $fn->appendBasicBlock('ftp_mkdir_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::mkdirArgv'),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $strPtr)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementDeleteBridge(Context $context): void
    {
        self::implementBoolStringBridge(
            $context,
            '__compiler_ftp_delete',
            'ftp_delete_entry',
            self::H.'::deleteArgv'
        );
    }

    private static function implementRmdirBridge(Context $context): void
    {
        self::implementBoolStringBridge(
            $context,
            '__compiler_ftp_rmdir',
            'ftp_rmdir_entry',
            self::H.'::rmdirArgv'
        );
    }

    private static function implementBoolStringBridge(
        Context $context,
        string $abiName,
        string $entryName,
        string $logical
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i1, false, $i64, $strPtr)
            );
        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $logical),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i1)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementRenameBridge(Context $context): void
    {
        $abiName = '__compiler_ftp_rename';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i1, false, $i64, $strPtr, $strPtr)
            );
        $entry = $fn->appendBasicBlock('ftp_rename_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::renameArgv'),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i1)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#31427');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31427'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after FtpMutateRuntime link (#31427)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
