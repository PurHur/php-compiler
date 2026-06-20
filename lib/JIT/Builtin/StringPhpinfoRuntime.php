<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_phpinfo / __compiler_phpcredits via PhpinfoJitHelper PHP (#9256).
 *
 * JIT uses compiled {@see \PHPCompiler\ext\standard\PhpinfoJitHelper}; AOT standalone keeps
 * {@see StringPhpinfoRuntimeLlvm} until VmInfo HTML compiles in native link (#9256).
 * php-src: ext/standard/info.c
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

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_phpinfo');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            StringPhpinfoRuntimeLlvm::implement($context);
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
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after PhpinfoJitHelper compile (#9256)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'PhpinfoJitHelper.php');
            if (null === $block) {
                throw new \LogicException('PhpinfoJitHelper.php parseAndCompile failed (#9256)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9256)');
            }
        }
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
