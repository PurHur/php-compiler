<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\BasicBlock;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for ini_get/ini_set/ini_restore via IniJitHelper PHP (#9249).
 *
 * Replaces ~1k-line LLVM ini tables. Semantics match {@see \PHPCompiler\ext\standard\VmIni}.
 * php-src: ext/standard/ini.c, main/php_ini.c
 */
final class IniRuntime
{
    private const HELPER_PATH = '/ext/standard/IniJitHelper.php';

    private const G_MEMORY_LIMIT = 'phpc_ini_memory_limit';

    private const G_SERIALIZE_PRECISION = 'phpc_ini_serialize_precision';

    private const INI_GET_HELPER = 'PHPCompiler\\ext\\standard\\IniJitHelper::iniGet';

    private const INI_SET_HELPER = 'PHPCompiler\\ext\\standard\\IniJitHelper::iniSet';

    private const INI_CFG_GET_HELPER = 'PHPCompiler\\ext\\standard\\IniJitHelper::iniCfgGet';

    private const INI_RESTORE_HELPER = 'PHPCompiler\\ext\\standard\\IniJitHelper::iniRestore';

    private const SERIALIZE_PRECISION_INT_HELPER = 'PHPCompiler\\ext\\standard\\IniJitHelper::getSerializePrecisionInt';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INI_GET_HELPER,
        self::INI_SET_HELPER,
        self::INI_CFG_GET_HELPER,
        self::INI_RESTORE_HELPER,
        self::SERIALIZE_PRECISION_INT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_ini_get');
        $cfgProbe = $context->module->getNamedFunction('__compiler_ini_cfg_get');
        if (null !== $probe && $probe->countBasicBlocks() > 0
            && null !== $cfgProbe && $cfgProbe->countBasicBlocks() > 0) {
            SilenceRuntime::ensureLinked($context);
            self::registerLinkedRuntime($context);

            return;
        }

        $restoreBlock = self::captureInsertBlock($context);
        SilenceRuntime::ensureLinked($context);
        self::ensureGlobals($context);
        self::ensureJitHelperCompiled($context);
        self::ensureValueWriters($context);
        self::implementIfMissing($context, '__compiler_ini_get', self::implementIniGetBridge(...));
        self::implementIfMissing($context, '__compiler_ini_cfg_get', self::implementIniCfgGetBridge(...));
        self::implementIfMissing($context, '__compiler_ini_set', self::implementIniSetBridge(...));
        self::implementIfMissing($context, '__compiler_ini_restore', self::implementIniRestoreBridge(...));
        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restoreBlock);
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

        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name, $probe);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name, ?LlvmFunction $probe): LlvmFunction
    {
        if (null !== $probe) {
            return $probe;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');

        return match ($name) {
            '__compiler_ini_get', '__compiler_ini_cfg_get' => $context->module->addFunction(
                $name,
                $context->context->functionType($voidTy, false, $strPtr, $valPtr)
            ),
            '__compiler_ini_set' => $context->module->addFunction(
                $name,
                $context->context->functionType($voidTy, false, $strPtr, $strPtr, $valPtr)
            ),
            '__compiler_ini_restore' => $context->module->addFunction(
                $name,
                $context->context->functionType($voidTy, false, $strPtr)
            ),
            default => throw new \LogicException('Unknown ini JIT ABI: '.$name),
        };
    }

    private static function implementIniGetBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ini_get_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::INI_GET_HELPER),
            [$fn->getParam(0)]
        );
        self::writeHelperStringOrFalseToValue($context, $fn->getParam(1), $result);
        $context->builder->returnVoid();
    }

    private static function implementIniCfgGetBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ini_cfg_get_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::INI_CFG_GET_HELPER),
            [$fn->getParam(0)]
        );
        self::writeHelperStringOrFalseToValue($context, $fn->getParam(1), $result);
        $context->builder->returnVoid();
    }

    private static function implementIniSetBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ini_set_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::INI_SET_HELPER),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        self::writeHelperStringOrFalseToValue($context, $fn->getParam(2), $result);
        self::syncSerializePrecisionGlobal($context);
        $context->builder->returnVoid();
    }

    private static function implementIniRestoreBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ini_restore_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            self::helperFunction($context, self::INI_RESTORE_HELPER),
            $fn->getParam(0)
        );
        self::syncSerializePrecisionGlobal($context);
        $context->builder->returnVoid();
    }

    private static function writeHelperStringOrFalseToValue(Context $context, Value $out, Value $raw): void
    {
        $i32 = $context->getTypeFromString('int32');
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $falseBb = BasicBlockHelper::append($context, 'ini_result_false');
        $okBb = BasicBlockHelper::append($context, 'ini_result_string');
        $doneBb = BasicBlockHelper::append($context, 'ini_result_done');

        $context->builder->branchIf($isNull, $falseBb, $okBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $str = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function syncSerializePrecisionGlobal(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $prec = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::SERIALIZE_PRECISION_INT_HELPER),
            []
        );
        $context->builder->store(
            $context->builder->trunc($prec, $i32),
            self::globalPtr($context, self::G_SERIALIZE_PRECISION, $i32)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after IniJitHelper compile (#9249)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'IniJitHelper.php');
            if (null === $block) {
                throw new \LogicException('IniJitHelper.php parseAndCompile failed (#9249)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9249)');
            }
        }
    }

    private static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        if (null === $context->module->getNamedGlobal(self::G_MEMORY_LIMIT)) {
            $g = $context->module->addGlobal($i8p, self::G_MEMORY_LIMIT);
            $g->setInitializer($i8p->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_SERIALIZE_PRECISION)) {
            $g = $context->module->addGlobal($i32, self::G_SERIALIZE_PRECISION);
            $g->setInitializer($i32->constInt(-1, true));
        }
    }

    private static function ensureValueWriters(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');

        self::ensureExternal(
            $context,
            '__value__writeString',
            $context->context->functionType($voidTy, false, $valPtr, $strPtr)
        );
        self::ensureExternal(
            $context,
            '__value__writeBool',
            $context->context->functionType($voidTy, false, $valPtr, $i32)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function globalPtr(Context $context, string $name, $llvmType): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('IniRuntime global missing: '.$name);
        }

        return $context->builder->pointerCast($global, $llvmType->pointerType(0));
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (
            [
                '__compiler_phpc_error_level_enabled',
                '__compiler_ini_get',
                '__compiler_ini_cfg_get',
                '__compiler_ini_set',
                '__compiler_ini_restore',
                '__compiler_error_reporting',
                '__compiler_begin_silence',
                '__compiler_end_silence',
            ] as $name
        ) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after IniRuntime bridge (#9249)');
            }
            $context->registerFunction($name, $fn);
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
