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
 * Shared LLVM ABI trampolines into compiled php-in-PHP JIT helpers (#9679).
 */
final class JitHelperAbiBridge
{
    /**
     * @param list<string> $compiledHelpers logical helper names (Class::method)
     * @param list<array{abi: string, helper: string, kind: string}> $bridges kind: void|bool_i32|obj_i64_void|i64_obj
     * @param list<string> $abiFunctions all ABI names to register after linking
     */
    public static function implement(
        Context $context,
        string $helperPath,
        string $helperFileName,
        string $issueTag,
        array $compiledHelpers,
        array $bridges,
        array $abiFunctions,
    ): void {
        $probeName = $abiFunctions[0] ?? $bridges[0]['abi'] ?? '';
        $probe = '' !== $probeName ? $context->module->getNamedFunction($probeName) : null;
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context, $abiFunctions, $issueTag);

            return;
        }

        self::ensureJitHelperCompiled($context, $helperPath, $helperFileName, $issueTag, $compiledHelpers);
        foreach ($bridges as $bridge) {
            match ($bridge['kind']) {
                'void' => self::implementVoidBridge($context, $bridge['abi'], $bridge['helper'], $issueTag),
                'bool_i32' => self::implementBoolI32Bridge($context, $bridge['abi'], $bridge['helper'], $issueTag),
                'obj_i64_void' => self::implementObjI64VoidBridge($context, $bridge['abi'], $bridge['helper'], $issueTag),
                'i64_obj' => self::implementI64ObjBridge($context, $bridge['abi'], $bridge['helper'], $issueTag),
                default => throw new \LogicException('unknown JitHelperAbiBridge kind: '.$bridge['kind']),
            };
        }
        self::registerLinkedRuntime($context, $abiFunctions, $issueTag);
        $context->builder->clearInsertionPosition();
    }

    private static function implementVoidBridge(Context $context, string $abiName, string $helperLogical, string $issueTag): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('jhab_void_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, $helperLogical, $issueTag));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementBoolI32Bridge(Context $context, string $abiName, string $helperLogical, string $issueTag): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('jhab_bool_i32_entry');
        $context->builder->positionAtEnd($entry);
        $pending = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical, $issueTag),
            []
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceHelperScalarResult($context, $pending, $i32)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function implementObjI64VoidBridge(Context $context, string $abiName, string $helperLogical, string $issueTag): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $objPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('jhab_obj_i64_void_entry');
        $context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        $context->builder->call(
            self::helperFunction($context, $helperLogical, $issueTag),
            $context->builder->ptrToInt($obj, $i64)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementI64ObjBridge(Context $context, string $abiName, string $helperLogical, string $issueTag): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($objPtr, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $prefix = 'jhab_'.preg_replace('/[^a-z0-9_]/', '_', strtolower($abiName));
        $entry = $fn->appendBasicBlock($prefix.'_entry');
        $nullBb = $fn->appendBasicBlock($prefix.'_null');
        $ptrBb = $fn->appendBasicBlock($prefix.'_ptr');
        $doneBb = $fn->appendBasicBlock($prefix.'_done');
        $context->builder->positionAtEnd($entry);

        $addr = $context->builder->call(self::helperFunction($context, $helperLogical, $issueTag));
        $isZero = $context->builder->icmp(
            Builder::INT_EQ,
            $addr,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($isZero, $nullBb, $ptrBb);

        $context->builder->positionAtEnd($ptrBb);
        $loaded = $context->builder->intToPtr($addr, $objPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($objPtr);
        $phi->addIncoming($loaded, $ptrBb);
        $phi->addIncoming($objPtr->constNull(), $nullBb);
        $context->builder->returnValue($phi);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical, string $issueTag): LlvmFunction
    {
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after JIT helper compile ('.$issueTag.')');
        }

        return $fn;
    }

    /**
     * @param list<string> $compiledHelpers
     */
    private static function ensureJitHelperCompiled(
        Context $context,
        string $helperPath,
        string $helperFileName,
        string $issueTag,
        array $compiledHelpers,
    ): void {
        $missing = false;
        foreach ($compiledHelpers as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).$helperPath;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path, $helperFileName, $issueTag, $compiledHelpers): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), $helperFileName);
            if (null === $block) {
                throw new \LogicException($helperFileName.' parseAndCompile failed ('.$issueTag.')');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach ($compiledHelpers as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT ('.$issueTag.')');
            }
        }
    }

    /**
     * @param list<string> $abiFunctions
     */
    private static function registerLinkedRuntime(Context $context, array $abiFunctions, string $issueTag): void
    {
        foreach ($abiFunctions as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after JitHelperAbiBridge ('.$issueTag.')');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
