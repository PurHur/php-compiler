<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_stat via StatArrayJitHelper PHP (#9585).
 *
 * Replaces {@see StringFsDirJit::emitStat} glibc struct stat LLVM walk.
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::statInfo()}
 * php-src: ext/standard/filestat.c — php_stat()
 */
final class StatArrayRuntime
{
    private const HELPER_PATH = '/ext/standard/StatArrayJitHelper.php';

    private const STAT_HELPER = 'PHPCompiler\\ext\\standard\\StatArrayJitHelper::statArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STAT_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__phpc_stat',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            StatArrayLlvm::implement($context);

            return;
        }

        $probe = $context->module->getNamedFunction('__phpc_stat');
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
        self::implementIfMissing($context, '__phpc_stat', self::implementStatBridge(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        return $context->module->addFunction(
            $name,
            $context->context->functionType($htPtr, false, $strPtr, $i32)
        );
    }

    private static function implementStatBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('stat_array_entry');
        $fail = $fn->appendBasicBlock('stat_array_fail');
        $body = $fn->appendBasicBlock('stat_array_body');
        $context->builder->positionAtEnd($entry);

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $path = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($body);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::STAT_HELPER),
            [$path, $fn->getParam(1)]
        );
        $htNull = JitNestedHelperCoerce::isHelperResultNull($context, $htRaw);
        $retBb = $fn->appendBasicBlock('stat_array_ret');
        $context->builder->branchIf($htNull, $fail, $retBb);

        $context->builder->positionAtEnd($retBb);
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $context->builder->returnValue($ht);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($htPtr->constNull());
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StatArrayJitHelper compile (#9585)');
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

        StatCacheRuntime::ensureLinked($context);
        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StatArrayJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StatArrayJitHelper.php parseAndCompile failed (#9585)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9585)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StatArrayRuntime bridge (#9585)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
