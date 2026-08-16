<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for ftp_login() via FtpLoginJitHelper PHP (#31378).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer FtpClose / #31377).
 * php-src: ext/ftp/ftp.c — PHP_FUNCTION(ftp_login)
 */
final class FtpLoginRuntime
{
    private const HELPER_PATH = '/ext/ftp/FtpLoginJitHelper.php';

    private const H = 'PHPCompiler\\ext\\ftp\\FtpLoginJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::loginArgv',
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_ftp_login',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_ftp_login');
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
        self::implementLoginBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementLoginBridge(Context $context): void
    {
        $abiName = '__compiler_ftp_login';
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

        $entry = $fn->appendBasicBlock('ftp_login_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::loginArgv'),
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#31378');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31378'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after FtpLoginRuntime link (#31378)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
