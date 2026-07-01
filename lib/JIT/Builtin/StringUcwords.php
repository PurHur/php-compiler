<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __string__ucwords / __string__ucwords_ex via UcwordsJitHelper PHP (#14717).
 *
 * Replaces ~189 LOC inline LLVM in StringUcwords.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — php_ucwords() / php_ucwords_ex()
 */
final class StringUcwords
{
    private const HELPER_PATH = '/ext/standard/UcwordsJitHelper.php';

    private const UCWORDS_HELPER = 'PHPCompiler\\ext\\standard\\UcwordsJitHelper::ucwordsArgv';

    private const UCWORDS_EX_HELPER = 'PHPCompiler\\ext\\standard\\UcwordsJitHelper::ucwordsExArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::UCWORDS_HELPER,
        self::UCWORDS_EX_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__string__ucwords',
        '__string__ucwords_ex',
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
        $probe = $context->module->getNamedFunction('__string__ucwords');
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
        self::implementBridge($context, '__string__ucwords', self::UCWORDS_HELPER, 1);
        self::implementBridge($context, '__string__ucwords_ex', self::UCWORDS_EX_HELPER, 2);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(
        Context $context,
        string $abiName,
        string $helperLogical,
        int $paramCount
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $params = array_fill(0, $paramCount, $strPtr);
        $ft = $context->context->functionType($strPtr, false, ...$params);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('ucwords_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $args = [];
        for ($i = 0; $i < $paramCount; ++$i) {
            $args[] = $fn->getParam($i);
        }
        $result = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            ...$args
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after UcwordsJitHelper compile (#14717)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'UcwordsJitHelper.php');
            if (null === $block) {
                throw new \LogicException('UcwordsJitHelper.php parseAndCompile failed (#14717)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#14717)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringUcwords bridge (#14717)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
