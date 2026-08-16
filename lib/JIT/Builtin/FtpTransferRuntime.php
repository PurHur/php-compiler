<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for ftp_get/put/fget/fput via FtpTransferJitHelper (#31429).
 */
final class FtpTransferRuntime
{
    private const HELPER_PATH = '/ext/ftp/FtpTransferJitHelper.php';

    private const H = 'PHPCompiler\\ext\\ftp\\FtpTransferJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::getArgv',
        self::H.'::putArgv',
        self::H.'::fgetArgv',
        self::H.'::fputArgv',
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_ftp_get',
        '__compiler_ftp_put',
        '__compiler_ftp_fget',
        '__compiler_ftp_fput',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_ftp_get');
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
        self::implementGetBridge($context);
        self::implementPutBridge($context);
        self::implementFgetBridge($context);
        self::implementFputBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementGetBridge(Context $context): void
    {
        self::implementPathTransferBridge(
            $context,
            '__compiler_ftp_get',
            'ftp_get_entry',
            self::H.'::getArgv'
        );
    }

    private static function implementPutBridge(Context $context): void
    {
        self::implementPathTransferBridge(
            $context,
            '__compiler_ftp_put',
            'ftp_put_entry',
            self::H.'::putArgv'
        );
    }

    private static function implementPathTransferBridge(
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
                $context->context->functionType($i1, false, $i64, $strPtr, $strPtr, $i64, $i64)
            );
        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $logical),
            [
                $fn->getParam(0),
                $fn->getParam(1),
                $fn->getParam(2),
                $fn->getParam(3),
                $fn->getParam(4),
            ]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i1)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementFgetBridge(Context $context): void
    {
        $abiName = '__compiler_ftp_fget';
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
                $context->context->functionType($i1, false, $i64, $i64, $strPtr, $i64, $i64)
            );
        $entry = $fn->appendBasicBlock('ftp_fget_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::fgetArgv'),
            [
                $fn->getParam(0),
                $fn->getParam(1),
                $fn->getParam(2),
                $fn->getParam(3),
                $fn->getParam(4),
            ]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i1)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementFputBridge(Context $context): void
    {
        $abiName = '__compiler_ftp_fput';
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
                $context->context->functionType($i1, false, $i64, $strPtr, $i64, $i64, $i64)
            );
        $entry = $fn->appendBasicBlock('ftp_fput_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::fputArgv'),
            [
                $fn->getParam(0),
                $fn->getParam(1),
                $fn->getParam(2),
                $fn->getParam(3),
                $fn->getParam(4),
            ]
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#31429');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31429'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after FtpTransferRuntime link (#31429)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
