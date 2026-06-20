<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for scalar dim fetch warnings via ScalarDimFetchJitHelper PHP (#10271).
 *
 * SSOT: {@see \PHPCompiler\VM\ErrorReporter}, {@see \PHPCompiler\VM\ScalarDimFetchJitHelper}
 */
final class ScalarDimFetchRuntime
{
    private const HELPER_PATH = '/lib/VM/ScalarDimFetchJitHelper.php';

    private const WARNING_MESSAGE_HELPER = 'PHPCompiler\\VM\\ScalarDimFetchJitHelper::warningMessageForJitType';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::WARNING_MESSAGE_HELPER,
    ];

    private const ABI_EMIT_WARNING = '__scalar_dim_fetch__emitWarning';

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
        $probe = $context->module->getNamedFunction(self::ABI_EMIT_WARNING);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementEmitWarningBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    public static function emitWarning(Context $context, int $jitType): void
    {
        self::ensureLinked($context);
        StringTriggerError::ensureLinked($context);
        $fn = $context->lookupFunction(self::ABI_EMIT_WARNING);
        $i8 = $context->getTypeFromString('int8');
        $context->builder->call(
            $fn,
            $i8->constInt($jitType, false)
        );
    }

    private static function implementEmitWarningBridge(Context $context): void
    {
        $abiName = self::ABI_EMIT_WARNING;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        StringTriggerError::ensureLinked($context);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i8);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('scalar_dim_fetch_warn_entry');
        $context->builder->positionAtEnd($entry);
        $msgStr = $context->builder->call(
            self::helperFunction($context, self::WARNING_MESSAGE_HELPER),
            $fn->getParam(0)
        );
        $strMap = $context->structFieldMap['__string__'];
        $msgLen = $context->builder->load(
            $context->builder->structGep($msgStr, $strMap['length'])
        );
        $msgBytes = $context->builder->structGep($msgStr, $strMap['value']);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $context->builder->pointerCast($msgBytes, $i8p),
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_EMIT_WARNING);
        if (null !== $fn) {
            $context->registerFunction(self::ABI_EMIT_WARNING, $fn);
        }
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ScalarDimFetchJitHelper compile (#10271)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ScalarDimFetchJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ScalarDimFetchJitHelper.php parseAndCompile failed (#10271)');
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
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#10271)');
            }
        }
    }
}
