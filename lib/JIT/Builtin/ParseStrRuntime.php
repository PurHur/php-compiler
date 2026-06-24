<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_parse_str via ParseStrJitHelper PHP (#9295).
 *
 * Replaces {@see StringParseStrJit} LLVM for the main parse_str entry; sub-helpers
 * remain in StringParseStrJit for superglobals/multipart until those paths migrate.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(parse_str)
 */
final class ParseStrRuntime
{
    private const HELPER_PATH = '/ext/standard/ParseStrJitHelper.php';

    private const PARSE_INTO_HELPER = 'PHPCompiler\\ext\\standard\\ParseStrJitHelper::parseInto';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PARSE_INTO_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_parse_str',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_parse_str');
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
        self::implementIfMissing($context, '__compiler_parse_str', self::implementParseBridge(...));
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
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->getTypeFromString('void');

        return $context->module->addFunction(
            $name,
            $context->context->functionType($void, false, $htPtr, $strPtr)
        );
    }

    private static function implementParseBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('parse_str_bridge_entry');
        $early = BasicBlockHelper::append($context, 'parse_str_bridge_early');
        $work = BasicBlockHelper::append($context, 'parse_str_bridge_work');
        $context->builder->positionAtEnd($entry);

        $dest = $fn->getParam(0);
        $encoded = $fn->getParam(1);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullDest = $context->builder->icmp(Builder::INT_EQ, $dest, $htPtr->constNull());
        $context->builder->branchIf($nullDest, $early, $work);

        $context->builder->positionAtEnd($early);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $context->builder->call(
            self::helperFunction($context, self::PARSE_INTO_HELPER),
            $dest,
            $encoded
        );
        $context->builder->returnVoid();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ParseStrJitHelper compile (#9295)');
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
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ParseStrJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ParseStrJitHelper.php parseAndCompile failed (#9295)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9295)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ParseStrRuntime bridge (#9295)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
