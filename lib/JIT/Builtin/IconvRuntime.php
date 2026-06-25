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
 * JIT/AOT link for __compiler_iconv via IconvJitHelper PHP (#9345).
 *
 * Replaces ~750-line CharsetEngine LLVM monolith. SSOT: {@see \PHPCompiler\ext\iconv\VmIconv}.
 * php-src: ext/iconv/iconv.c — PHP_FUNCTION(iconv)
 */
final class IconvRuntime
{
    private const HELPER_PATH = '/ext/iconv/IconvJitHelper.php';

    private const CONVERT_HELPER = 'PHPCompiler\\ext\\iconv\\IconvJitHelper::convert';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CONVERT_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_iconv',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_iconv');
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
        self::implementIfMissing($context, '__compiler_iconv', self::implementIconvBridge(...));
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

        $strPtr = $context->getTypeFromString('__string__*');

        return $context->module->addFunction(
            $name,
            $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr)
        );
    }

    private static function implementIconvBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('iconv_bridge_entry');
        $fail = $fn->appendBasicBlock('iconv_bridge_fail');
        $body = $fn->appendBasicBlock('iconv_bridge_body');
        $context->builder->positionAtEnd($entry);

        $strPtr = $context->getTypeFromString('__string__*');
        $from = $fn->getParam(0);
        $to = $fn->getParam(1);
        $input = $fn->getParam(2);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $from, $strPtr->constNull()),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $to, $strPtr->constNull()),
                $context->builder->icmp(Builder::INT_EQ, $input, $strPtr->constNull())
            )
        );
        $context->builder->branchIf($bad, $fail, $body);

        $context->builder->positionAtEnd($body);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::CONVERT_HELPER),
            [$from, $to, $input]
        );
        $result = JitNestedHelperCoerce::coerceBridgeResult($context, $resultRaw, $strPtr);
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($strPtr->constNull());
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after IconvJitHelper compile (#9345)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'IconvJitHelper.php');
            if (null === $block) {
                throw new \LogicException('IconvJitHelper.php parseAndCompile failed (#9345)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT iconv (#9345)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after IconvRuntime bridge (#9345)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
