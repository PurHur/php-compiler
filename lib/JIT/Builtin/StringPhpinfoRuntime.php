<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_phpinfo / __compiler_phpcredits via PhpinfoJitHelper PHP (#9256, #25931).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer SessionGc #25916).
 * JIT embed and AOT standalone compile {@see \PHPCompiler\ext\standard\PhpinfoJitHelper}; thin LLVM bridges
 * forward the ABI. php-src: ext/standard/info.c
 */
final class StringPhpinfoRuntime
{
    private const HELPER_PATH = '/ext/standard/PhpinfoJitHelper.php';

    private const PHPINFO_HELPER = 'PHPCompiler\\ext\\standard\\PhpinfoJitHelper::phpinfo';

    private const PHPCREDITS_HELPER = 'PHPCompiler\\ext\\standard\\PhpinfoJitHelper::phpcredits';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PHPINFO_HELPER,
        self::PHPCREDITS_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_phpinfo',
        '__compiler_phpcredits',
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
        $probe = $context->module->getNamedFunction('__compiler_phpinfo');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        ObOutput::registerExternals($context);
        ObOutputRuntime::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementPhpinfoBridge($context);
        self::implementPhpcreditsBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementPhpinfoBridge(Context $context): void
    {
        $abiName = '__compiler_phpinfo';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i32, false, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('phpinfo_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::PHPINFO_HELPER),
            $context->builder->sext($fn->getParam(0), $i64)
        );
        $context->builder->returnValue($context->builder->zext($result, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementPhpcreditsBridge(Context $context): void
    {
        $abiName = '__compiler_phpcredits';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('phpcredits_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            self::helperFunction($context, self::PHPCREDITS_HELPER),
            $context->builder->sext($fn->getParam(0), $i64)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25931');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25931'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringPhpinfoRuntime bridge (#9256)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
