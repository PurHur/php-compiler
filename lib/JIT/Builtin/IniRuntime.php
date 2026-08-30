<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for ini_get/ini_set/ini_restore via IniJitHelper PHP (#9249, #21200, #32779, #33059).
 *
 * Embed + thin standalone AOT: NestedJIT {@see \PHPCompiler\ext\standard\IniJitHelper}
 * via {@see JitVmHelperLink} (IncludePath #20877 / RandomBytes #21186 shape — no thin
 * false/nop stub fork). Semantics match {@see \PHPCompiler\ext\standard\VmIni}.
 * Owns `__compiler_ini_*` ABI module-locally (getNamedFunction first) after Type
 * always-on shells dropped (#32779 / #32122 name.1 class).
 * Thin AOT: helper uses {@see string}|false (not ?string) + {@see ensureCompiledModuleDefaults}
 * because NestedJIT BSS-zeros PHP static defaults and nullable-string ABI collapsed to "0" (#33059).
 * php-src: ext/standard/ini.c, main/php_ini.c
 */
final class IniRuntime
{
    private const HELPER_PATH = '/ext/standard/IniJitHelper.php';

    private const LEAF_GET_PATH = '/ext/standard/IniGetLeafJitHelper.php';

    private const G_MEMORY_LIMIT = 'phpc_ini_memory_limit';

    private const G_SERIALIZE_PRECISION = 'phpc_ini_serialize_precision';

    private const G_PRECISION = 'phpc_ini_precision';

    /** php-src EG(exception_ignore_args) — thin i8 global for AOT (#27549 / #21998). */
    private const G_EXCEPTION_IGNORE_ARGS = 'phpc_ini_exception_ignore_args';

    private const EXCEPTION_IGNORE_ARGS_KEY = 'zend.exception_ignore_args';

    private const INI_GET_HELPER = 'PHPCompiler\\ext\\standard\\IniGetLeafJitHelper::iniGet';

    private const INI_SET_HELPER = 'PHPCompiler\\ext\\standard\\IniGetLeafJitHelper::iniSet';

    private const INI_CFG_GET_HELPER = 'PHPCompiler\\ext\\standard\\IniJitHelper::iniCfgGet';

    private const INI_RESTORE_HELPER = 'PHPCompiler\\ext\\standard\\IniGetLeafJitHelper::iniRestore';

    private const SERIALIZE_PRECISION_INT_HELPER = 'PHPCompiler\\ext\\standard\\IniGetLeafJitHelper::getSerializePrecisionInt';

    private const PRECISION_INT_HELPER = 'PHPCompiler\\ext\\standard\\IniGetLeafJitHelper::getPrecisionInt';

    private const ENSURE_DEFAULTS_HELPER = 'PHPCompiler\\ext\\standard\\IniJitHelper::ensureCompiledModuleDefaults';

    private const GET_BRIDGE_ENTRY = 'ini_get_bridge_entry';

    private const CFG_GET_BRIDGE_ENTRY = 'ini_cfg_get_bridge_entry';

    private const SET_BRIDGE_ENTRY = 'ini_set_bridge_entry';

    private const RESTORE_BRIDGE_ENTRY = 'ini_restore_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INI_GET_HELPER,
        self::INI_SET_HELPER,
        self::INI_CFG_GET_HELPER,
        self::INI_RESTORE_HELPER,
        self::SERIALIZE_PRECISION_INT_HELPER,
        self::PRECISION_INT_HELPER,
        self::ENSURE_DEFAULTS_HELPER,
    ];

    /** @var list<string> */
    private const HELPER_BUNDLE_PATHS = [
        '/ext/standard/OutputRewriteVarsJitHelper.php',
        self::HELPER_PATH,
        self::LEAF_GET_PATH,
    ];

    /** @var list<string> */
    private const HELPER_BUNDLE_METHODS = [
        'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::getTags',
        'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::setTags',
        'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::getHosts',
        'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::setHosts',
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
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        // Thin + embed: publish sg_vm_context before NestedJIT of IniJitHelper (#21200 / #17391).
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);

        $probe = $context->module->getNamedFunction('__compiler_ini_get');
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::GET_BRIDGE_ENTRY)) {
            SilenceRuntime::ensureLinked($context);
            self::ensureGlobals($context);
            // Pick up newly-listed helpers (e.g. getPrecisionInt #21963) without rebuilding bridges.
            // url_rewriter.* tags/hosts on OutputRewriteVarsJitHelper (#27566).
            JitVmHelperLink::ensureCompiledBundle(
                $context,
                self::HELPER_BUNDLE_PATHS,
                array_merge(self::HELPER_BUNDLE_METHODS, self::COMPILED_HELPERS),
                '#21200'
            );
            self::registerLinkedRuntime($context);

            return;
        }

        $restoreBlock = self::captureInsertBlock($context);
        SilenceRuntime::ensureLinked($context);
        self::ensureGlobals($context);
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE_PATHS,
            array_merge(self::HELPER_BUNDLE_METHODS, self::COMPILED_HELPERS),
            '#21200'
        );
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
        $entry = match ($name) {
            '__compiler_ini_get' => self::GET_BRIDGE_ENTRY,
            '__compiler_ini_cfg_get' => self::CFG_GET_BRIDGE_ENTRY,
            '__compiler_ini_set' => self::SET_BRIDGE_ENTRY,
            '__compiler_ini_restore' => self::RESTORE_BRIDGE_ENTRY,
            default => '',
        };
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entry)) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name, $probe);
        BasicBlockHelper::scopeLoweringToFunction($context, $fn, $name, static function () use ($context, $fn, $emit): void {
            $emit($context, $fn);
        });
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
        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::GET_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $option = $fn->getParam(0);
        $out = $fn->getParam(1);
        // Leaf only — LLVM strcasecmp thin dispatch always matched exception_ignore_args (#33059).
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::INI_GET_HELPER),
            [$option]
        );
        self::writeHelperStringOrFalseToValue($context, $out, $result);
        $context->builder->returnVoid();
    }

    private static function implementIniCfgGetBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::CFG_GET_BRIDGE_ENTRY);
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
        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::SET_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $option = $fn->getParam(0);
        $value = $fn->getParam(1);
        $out = $fn->getParam(2);
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::INI_SET_HELPER),
            [$option, $value]
        );
        self::writeHelperStringOrFalseToValue($context, $out, $result);
        self::syncSerializePrecisionGlobal($context);
        self::syncPrecisionGlobal($context);
        $context->builder->returnVoid();
    }

    private static function implementIniRestoreBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::RESTORE_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $option = $fn->getParam(0);
        $context->builder->call(
            self::helperFunction($context, self::INI_RESTORE_HELPER),
            $option
        );
        self::syncSerializePrecisionGlobal($context);
        self::syncPrecisionGlobal($context);
        $context->builder->returnVoid();
    }

    /**
     * Runtime load of EG(exception_ignore_args) for AOT exception-trace seeding (#27549).
     * Caller must {@see ensureLinked()} first so the thin global exists.
     */
    public static function loadExceptionIgnoreArgs(Context $context): Value
    {
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load(self::globalPtr($context, self::G_EXCEPTION_IGNORE_ARGS, $i8)),
            $i8->constInt(0, false)
        );
    }

    /** VmIni::parseBoolIni — thin strcmp table (empty/0/off/false → false). */
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

    private static function syncPrecisionGlobal(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $prec = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::PRECISION_INT_HELPER),
            []
        );
        $context->builder->store(
            $context->builder->trunc($prec, $i32),
            self::globalPtr($context, self::G_PRECISION, $i32)
        );
    }

    /** Load PG(precision) for float→string lowering (#21963). */
    public static function loadPrecision(Context $context): Value
    {
        self::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->load(self::globalPtr($context, self::G_PRECISION, $i32));
    }

    /** Load PG(serialize_precision) for php_var_dump %.*H (#32328). */
    public static function loadSerializePrecision(Context $context): Value
    {
        self::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->load(self::globalPtr($context, self::G_SERIALIZE_PRECISION, $i32));
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        return JitVmHelperLink::lookupCompiled($context, $logical, '#21200');
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
        if (null === $context->module->getNamedGlobal(self::G_PRECISION)) {
            $g = $context->module->addGlobal($i32, self::G_PRECISION);
            $g->setInitializer($i32->constInt(14, true));
        }
        $i8 = $context->getTypeFromString('int8');
        if (null === $context->module->getNamedGlobal(self::G_EXCEPTION_IGNORE_ARGS)) {
            // php-src EG(exception_ignore_args) compiled default Off (#28061).
            $g = $context->module->addGlobal($i8, self::G_EXCEPTION_IGNORE_ARGS);
            $g->setInitializer($i8->constInt(0, false));
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
        \PHPCompiler\JIT\LibcExtern::ensureExternalDecl($context, $name, $ft);
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
