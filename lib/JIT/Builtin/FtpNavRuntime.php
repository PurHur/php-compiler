<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for ftp_pasv/chdir/cdup/pwd via FtpNavJitHelper (#31379).
 */
final class FtpNavRuntime
{
    private const HELPER_PATH = '/ext/ftp/FtpNavJitHelper.php';

    private const H = 'PHPCompiler\\ext\\ftp\\FtpNavJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::pasvArgv',
        self::H.'::chdirArgv',
        self::H.'::cdupArgv',
        self::H.'::pwdArgv',
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_ftp_pasv',
        '__compiler_ftp_chdir',
        '__compiler_ftp_cdup',
        '__compiler_ftp_pwd',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_ftp_pasv');
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
        self::implementPasvBridge($context);
        self::implementChdirBridge($context);
        self::implementCdupBridge($context);
        self::implementPwdBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementPasvBridge(Context $context): void
    {
        self::implementBoolBridge(
            $context,
            '__compiler_ftp_pasv',
            'ftp_pasv_entry',
            self::H.'::pasvArgv',
            true
        );
    }

    private static function implementChdirBridge(Context $context): void
    {
        $abiName = '__compiler_ftp_chdir';
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
        $entry = $fn->appendBasicBlock('ftp_chdir_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::chdirArgv'),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i1)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementCdupBridge(Context $context): void
    {
        self::implementBoolBridge(
            $context,
            '__compiler_ftp_cdup',
            'ftp_cdup_entry',
            self::H.'::cdupArgv',
            false
        );
    }

    private static function implementPwdBridge(Context $context): void
    {
        $abiName = '__compiler_ftp_pwd';
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
                $context->context->functionType($strPtr, false, $i64)
            );
        $entry = $fn->appendBasicBlock('ftp_pwd_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::pwdArgv'),
            [$fn->getParam(0)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $strPtr)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBoolBridge(
        Context $context,
        string $abiName,
        string $entryName,
        string $logical,
        bool $withBool
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : ($withBool
                ? $context->module->addFunction(
                    $abiName,
                    $context->context->functionType($i1, false, $i64, $i1)
                )
                : $context->module->addFunction(
                    $abiName,
                    $context->context->functionType($i1, false, $i64)
                ));
        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);
        $params = $withBool
            ? [$fn->getParam(0), $fn->getParam(1)]
            : [$fn->getParam(0)];
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $logical),
            $params
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#31379');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31379'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after FtpNavRuntime link (#31379)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
