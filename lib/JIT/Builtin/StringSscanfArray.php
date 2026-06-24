<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_sscanf_array via SscanfJitHelper PHP (#9134).
 *
 * JIT embed uses compiled {@see SscanfJitHelper}; AOT standalone keeps
 * {@see SscanfJit} LLVM until native link can host compiled VmSscanf reliably.
 * php-src: ext/standard/sscanf.c — PHP_FUNCTION(sscanf) array return branch
 */
final class StringSscanfArray
{
    private const ABI_NAME = '__compiler_sscanf_array';

    private const HELPER_PATH = '/ext/standard/SscanfJitHelper.php';

    private const PARSE_ARRAY_HELPER = 'PHPCompiler\\ext\\standard\\SscanfJitHelper::parseToArray';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PARSE_ARRAY_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureRuntimeHelpers($context);
        self::ensureJitHelperCompiled($context);
        self::implementArrayBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementArrayBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($valuePtr, false, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('sscanf_arr_bridge_entry');
        $nullOutBb = $fn->appendBasicBlock('sscanf_arr_bridge_null');
        $arrayOutBb = $fn->appendBasicBlock('sscanf_arr_bridge_array');
        $context->builder->positionAtEnd($entry);

        $ht = $context->builder->call(
            self::helperFunction($context, self::PARSE_ARRAY_HELPER),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $context->builder->branchIf($htNull, $nullOutBb, $arrayOutBb);

        $context->builder->positionAtEnd($nullOutBb);
        $nullSlot = JitValueBox::alloc($context);
        $nullPtr = JitValueBox::pointer($context, $nullSlot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);
        $context->builder->returnValue($nullPtr);

        $context->builder->positionAtEnd($arrayOutBb);
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $resultPtr,
            $ht
        );
        $context->builder->returnValue($resultPtr);
        $context->registerFunction(self::ABI_NAME, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SscanfJitHelper compile (#9134)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'SscanfJitHelper.php');
            if (null === $block) {
                throw new \LogicException('SscanfJitHelper.php parseAndCompile failed (#9134)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9134)');
            }
        }
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $void = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        foreach ([
            ['__value__writeNull', $void, [$valuePtr]],
            ['__value__writeHashtable', $void, [$valuePtr, $htPtr]],
        ] as [$name, $ret, $params]) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction($name, $context->context->functionType($ret, false, ...$params));
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after StringSscanfArray bridge (#9134)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
