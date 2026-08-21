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
 * JIT/AOT link for ini_get/ini_set/ini_restore via IniJitHelper PHP (#9249, #21200, #32779).
 *
 * Embed + thin standalone AOT: NestedJIT {@see \PHPCompiler\ext\standard\IniJitHelper}
 * via {@see JitVmHelperLink} (IncludePath #20877 / RandomBytes #21186 shape — no thin
 * false/nop stub fork). Semantics match {@see \PHPCompiler\ext\standard\VmIni}.
 * Owns `__compiler_ini_*` ABI module-locally (getNamedFunction first) after Type
 * always-on shells dropped (#32779 / #32122 name.1 class).
 * php-src: ext/standard/ini.c, main/php_ini.c
 */
final class IniRuntime
{
    private const HELPER_PATH = '/ext/standard/IniJitHelper.php';

    private const G_MEMORY_LIMIT = 'phpc_ini_memory_limit';

    private const G_SERIALIZE_PRECISION = 'phpc_ini_serialize_precision';

    private const G_PRECISION = 'phpc_ini_precision';

    /** php-src EG(exception_ignore_args) — thin i8 global for AOT (#27549 / #21998). */
    private const G_EXCEPTION_IGNORE_ARGS = 'phpc_ini_exception_ignore_args';

    private const G_DEFAULTS_SEEDED = 'phpc_ini_jit_defaults_seeded';

    private const EXCEPTION_IGNORE_ARGS_KEY = 'zend.exception_ignore_args';

    private const INI_GET_HELPER = 'PHPCompiler\\ext\\standard\\IniJitHelper::iniGet';

    private const INI_SET_HELPER = 'PHPCompiler\\ext\\standard\\IniJitHelper::iniSet';

    private const INI_CFG_GET_HELPER = 'PHPCompiler\\ext\\standard\\IniJitHelper::iniCfgGet';

    private const INI_RESTORE_HELPER = 'PHPCompiler\\ext\\standard\\IniJitHelper::iniRestore';

    private const SERIALIZE_PRECISION_INT_HELPER = 'PHPCompiler\\ext\\standard\\IniJitHelper::getSerializePrecisionInt';

    private const PRECISION_INT_HELPER = 'PHPCompiler\\ext\\standard\\IniJitHelper::getPrecisionInt';

    private const RESET_DEFAULTS_HELPER = 'PHPCompiler\\ext\\standard\\IniJitHelper::resetCompiledModuleDefaults';

    private const FORMAT_SIGNED_INT_HELPER = 'PHPCompiler\\ext\\standard\\IniJitHelper::formatSignedIntForIni';

    private const GET_BRIDGE_ENTRY = 'ini_get_bridge_entry';

    private const CFG_GET_BRIDGE_ENTRY = 'ini_cfg_get_bridge_entry';

    private const SET_BRIDGE_ENTRY = 'ini_set_bridge_entry';

    private const RESTORE_BRIDGE_ENTRY = 'ini_restore_bridge_entry';

    private const KEY_PRECISION = 'precision';

    private const KEY_SERIALIZE_PRECISION = 'serialize_precision';

    private const KEY_MEMORY_LIMIT = 'memory_limit';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INI_GET_HELPER,
        self::INI_SET_HELPER,
        self::INI_CFG_GET_HELPER,
        self::INI_RESTORE_HELPER,
        self::SERIALIZE_PRECISION_INT_HELPER,
        self::PRECISION_INT_HELPER,
        self::RESET_DEFAULTS_HELPER,
        self::FORMAT_SIGNED_INT_HELPER,
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
                [
                    '/ext/standard/OutputRewriteVarsJitHelper.php',
                    self::HELPER_PATH,
                ],
                array_merge(
                    [
                        'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::getTags',
                        'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::setTags',
                        'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::getHosts',
                        'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::setHosts',
                    ],
                    self::COMPILED_HELPERS
                ),
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
            [
                '/ext/standard/OutputRewriteVarsJitHelper.php',
                self::HELPER_PATH,
            ],
            array_merge(
                [
                    'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::getTags',
                    'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::setTags',
                    'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::getHosts',
                    'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::setHosts',
                ],
                self::COMPILED_HELPERS
            ),
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
        StringCaseCompare::ensureStrcasecmpLinked($context);
        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::GET_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $option = $fn->getParam(0);
        $out = $fn->getParam(1);

        // Thin LLVM SSOT for keys whose NestedJIT sprintf/static paths return "0" (#33059).
        self::emitEnsureCompiledDefaults($context);
        $precBb = BasicBlockHelper::append($context, 'ini_get_precision_thin');
        $serCheck = BasicBlockHelper::append($context, 'ini_get_ser_check');
        $serBb = BasicBlockHelper::append($context, 'ini_get_serialize_precision_thin');
        $memCheck = BasicBlockHelper::append($context, 'ini_get_mem_check');
        $memBb = BasicBlockHelper::append($context, 'ini_get_memory_limit_thin');
        $ignoreCheckBb = BasicBlockHelper::append($context, 'ini_get_ignore_check');
        $thinIgnoreBb = BasicBlockHelper::append($context, 'ini_get_ignore_args_thin');
        $nestedBb = BasicBlockHelper::append($context, 'ini_get_nested');
        $doneBb = BasicBlockHelper::append($context, 'ini_get_done');

        $context->builder->branchIf(
            self::emitOptionEqualsKey($context, $option, self::KEY_PRECISION),
            $precBb,
            $serCheck
        );
        $context->builder->positionAtEnd($serCheck);
        $context->builder->branchIf(
            self::emitOptionEqualsKey($context, $option, self::KEY_SERIALIZE_PRECISION),
            $serBb,
            $memCheck
        );
        $context->builder->positionAtEnd($memCheck);
        $context->builder->branchIf(
            self::emitOptionEqualsKey($context, $option, self::KEY_MEMORY_LIMIT),
            $memBb,
            $ignoreCheckBb
        );

        $context->builder->positionAtEnd($precBb);
        self::emitThinGetI32IniString($context, $out, self::G_PRECISION);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($serBb);
        self::emitThinGetI32IniString($context, $out, self::G_SERIALIZE_PRECISION);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($memBb);
        self::emitThinGetMemoryLimit($context, $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($ignoreCheckBb);
        $isIgnore = self::emitOptionIsExceptionIgnoreArgs($context, $option);
        $context->builder->branchIf($isIgnore, $thinIgnoreBb, $nestedBb);

        $context->builder->positionAtEnd($thinIgnoreBb);
        self::emitThinGetExceptionIgnoreArgs($context, $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($nestedBb);
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::INI_GET_HELPER),
            [$option]
        );
        self::writeHelperStringOrFalseToValue($context, $out, $result);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function implementIniCfgGetBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::CFG_GET_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        self::emitEnsureCompiledDefaults($context);
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
        StringCaseCompare::ensureStrcasecmpLinked($context);
        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::SET_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $option = $fn->getParam(0);
        $value = $fn->getParam(1);
        $out = $fn->getParam(2);
        $isIgnore = self::emitOptionIsExceptionIgnoreArgs($context, $option);
        $thinBb = BasicBlockHelper::append($context, 'ini_set_ignore_args_thin');
        $nestedBb = BasicBlockHelper::append($context, 'ini_set_nested');
        $doneBb = BasicBlockHelper::append($context, 'ini_set_done');
        $context->builder->branchIf($isIgnore, $thinBb, $nestedBb);

        $context->builder->positionAtEnd($thinBb);
        self::emitThinSetExceptionIgnoreArgs($context, $value, $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($nestedBb);
        self::emitEnsureCompiledDefaults($context);
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::INI_SET_HELPER),
            [$option, $value]
        );
        self::writeHelperStringOrFalseToValue($context, $out, $result);
        self::syncSerializePrecisionGlobal($context);
        self::syncPrecisionGlobal($context);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function implementIniRestoreBridge(Context $context, LlvmFunction $fn): void
    {
        StringCaseCompare::ensureStrcasecmpLinked($context);
        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::RESTORE_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $option = $fn->getParam(0);
        $isIgnore = self::emitOptionIsExceptionIgnoreArgs($context, $option);
        $thinBb = BasicBlockHelper::append($context, 'ini_restore_ignore_args_thin');
        $nestedBb = BasicBlockHelper::append($context, 'ini_restore_nested');
        $doneBb = BasicBlockHelper::append($context, 'ini_restore_done');
        $context->builder->branchIf($isIgnore, $thinBb, $nestedBb);

        $context->builder->positionAtEnd($thinBb);
        // php-src compiled default Off (`"0"`) — Zend/zend.c (#28061).
        $i8 = $context->getTypeFromString('int8');
        $context->builder->store(
            $i8->constInt(0, false),
            self::globalPtr($context, self::G_EXCEPTION_IGNORE_ARGS, $i8)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($nestedBb);
        self::emitEnsureCompiledDefaults($context);
        $context->builder->call(
            self::helperFunction($context, self::INI_RESTORE_HELPER),
            $option
        );
        self::syncSerializePrecisionGlobal($context);
        self::syncPrecisionGlobal($context);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
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

    /**
     * Compile-time option key → thin write into {@see $out} (#33059).
     * Unknown keys go through NestedJIT {@see IniJitHelper::iniGet}.
     */
    public static function emitThinGetKnownKey(Context $context, Value $out, string $key): void
    {
        self::ensureLinked($context);
        self::emitEnsureCompiledDefaults($context);
        if (self::KEY_PRECISION === $key) {
            self::emitThinGetI32IniString($context, $out, self::G_PRECISION);

            return;
        }
        if (self::KEY_SERIALIZE_PRECISION === $key) {
            self::emitThinGetI32IniString($context, $out, self::G_SERIALIZE_PRECISION);

            return;
        }
        if (self::KEY_MEMORY_LIMIT === $key) {
            self::emitThinGetMemoryLimit($context, $out);

            return;
        }
        if (self::EXCEPTION_IGNORE_ARGS_KEY === $key) {
            self::emitThinGetExceptionIgnoreArgs($context, $out);

            return;
        }
        // Literal unknown key → false (Zend). Avoid NestedJIT null→"0" ABI (#33059).
        if (!\PHPCompiler\ext\standard\IniJitHelper::isSupportedIniKey($key)
            && null === \PHPCompiler\ext\standard\VmIniIntrospection::mirroredHostIniGet($key)
            && !\PHPCompiler\ext\soap\SoapWsdlCache::isIniKey($key)
        ) {
            $i32 = $context->getTypeFromString('int32');
            $context->builder->call(
                $context->lookupFunction('__value__writeBool'),
                $out,
                $i32->constInt(0, false)
            );

            return;
        }
        $option = $context->builder->load($context->constantStringFromString($key));
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::INI_GET_HELPER),
            [$option]
        );
        self::writeHelperStringOrFalseToValue($context, $out, $result);
    }

    /** Literal ini_restore for thin-backed keys (#33059 / ini_precision.phpt). */
    public static function emitThinRestoreKnownKey(Context $context, string $key): void
    {
        self::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        if (self::KEY_PRECISION === $key) {
            $context->builder->store(
                $i32->constInt(14, true),
                self::globalPtr($context, self::G_PRECISION, $i32)
            );

            return;
        }
        if (self::KEY_SERIALIZE_PRECISION === $key) {
            $context->builder->store(
                $i32->constInt(-1, true),
                self::globalPtr($context, self::G_SERIALIZE_PRECISION, $i32)
            );

            return;
        }
        $option = $context->builder->load($context->constantStringFromString($key));
        $context->builder->call(
            self::helperFunction($context, self::INI_RESTORE_HELPER),
            $option
        );
    }

    /**
     * Literal ini_set for precision/serialize_precision — write old to {@see $out}, store new (#33059).
     */
    public static function emitThinSetI32Ini(Context $context, Value $out, string $key, string $newValue): void
    {
        self::ensureLinked($context);
        self::emitEnsureCompiledDefaults($context);
        $global = self::KEY_PRECISION === $key ? self::G_PRECISION : self::G_SERIALIZE_PRECISION;
        self::emitThinGetI32IniString($context, $out, $global);
        $i32 = $context->getTypeFromString('int32');
        $trimmed = trim($newValue);
        $parsed = '' === $trimmed ? -1 : intval($trimmed);
        $context->builder->store(
            $i32->constInt($parsed, true),
            self::globalPtr($context, $global, $i32)
        );
    }

    private static function emitOptionIsExceptionIgnoreArgs(Context $context, Value $optionStr): Value
    {
        return self::emitOptionEqualsKey($context, $optionStr, self::EXCEPTION_IGNORE_ARGS_KEY);
    }

    private static function emitOptionEqualsKey(Context $context, Value $optionStr, string $key): Value
    {
        // Use rodata constantFromString (not BSS constantStringFromString): helper-runtime
        // / NestedJIT emission can leave string_const_* slots unset so every key compares
        // equal and the first thin branch wins (#33059 — all ini_get → "14").
        StringCaseCompare::ensureStrcasecmpLinked($context);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $strMap = $context->structFieldMap['__string__'];
        $optCstr = $context->builder->pointerCast(
            $context->builder->structGep($optionStr, $strMap['value']),
            $i8p
        );
        $wantCstr = $context->builder->pointerCast($context->constantFromString($key), $i8p);
        $cmp = $context->builder->call(
            $context->lookupFunction(StringCaseCompare::ABI_STRCASECMP),
            $optCstr,
            $wantCstr
        );

        return $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
    }

    /**
     * Render PG(precision)/PG(serialize_precision) from the thin i32 global (#33059).
     * Avoid NestedJIT sprintf — it drops the int and returns "0".
     */
    private static function emitThinGetI32IniString(Context $context, Value $out, string $globalName): void
    {
        $i32 = $context->getTypeFromString('int32');
        $n = $context->builder->load(self::globalPtr($context, $globalName, $i32));
        $is14 = $context->builder->icmp(Builder::INT_EQ, $n, $i32->constInt(14, true));
        $is8 = $context->builder->icmp(Builder::INT_EQ, $n, $i32->constInt(8, true));
        $isNeg1 = $context->builder->icmp(Builder::INT_EQ, $n, $i32->constInt(-1, true));
        $is0 = $context->builder->icmp(Builder::INT_EQ, $n, $i32->constInt(0, true));

        $bb14 = BasicBlockHelper::append($context, 'ini_i32_str_14');
        $bb8 = BasicBlockHelper::append($context, 'ini_i32_str_8');
        $bbNeg1 = BasicBlockHelper::append($context, 'ini_i32_str_m1');
        $bb0 = BasicBlockHelper::append($context, 'ini_i32_str_0');
        $bbFmt = BasicBlockHelper::append($context, 'ini_i32_str_fmt');
        $done = BasicBlockHelper::append($context, 'ini_i32_str_done');
        $not14 = BasicBlockHelper::append($context, 'ini_i32_str_not14');
        $not8 = BasicBlockHelper::append($context, 'ini_i32_str_not8');
        $notNeg1 = BasicBlockHelper::append($context, 'ini_i32_str_notm1');

        $context->builder->branchIf($is14, $bb14, $not14);
        $context->builder->positionAtEnd($not14);
        $context->builder->branchIf($is8, $bb8, $not8);
        $context->builder->positionAtEnd($not8);
        $context->builder->branchIf($isNeg1, $bbNeg1, $notNeg1);
        $context->builder->positionAtEnd($notNeg1);
        $context->builder->branchIf($is0, $bb0, $bbFmt);

        $context->builder->positionAtEnd($bb14);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            $context->builder->load($context->constantStringFromString('14'))
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($bb8);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            $context->builder->load($context->constantStringFromString('8'))
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($bbNeg1);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            $context->builder->load($context->constantStringFromString('-1'))
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($bb0);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            $context->builder->load($context->constantStringFromString('0'))
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($bbFmt);
        $i64 = $context->getTypeFromString('int64');
        $asI64 = $context->builder->sext($n, $i64);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::FORMAT_SIGNED_INT_HELPER),
            [$asI64]
        );
        $str = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /** php-src memory_limit compiled default "-1" when BSS string static is unset (#33059). */
    private static function emitThinGetMemoryLimit(Context $context, Value $out): void
    {
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            $context->builder->load($context->constantStringFromString('-1'))
        );
    }

    private static function emitThinGetExceptionIgnoreArgs(Context $context, Value $out): void
    {
        $i8 = $context->getTypeFromString('int8');
        $on = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load(self::globalPtr($context, self::G_EXCEPTION_IGNORE_ARGS, $i8)),
            $i8->constInt(0, false)
        );
        $onBb = BasicBlockHelper::append($context, 'ini_get_ignore_on');
        $offBb = BasicBlockHelper::append($context, 'ini_get_ignore_off');
        $doneBb = BasicBlockHelper::append($context, 'ini_get_ignore_done');
        $context->builder->branchIf($on, $onBb, $offBb);

        $context->builder->positionAtEnd($onBb);
        $one = $context->builder->load($context->constantStringFromString('1'));
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $one);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($offBb);
        $zero = $context->builder->load($context->constantStringFromString('0'));
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $zero);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitThinSetExceptionIgnoreArgs(Context $context, Value $valueStr, Value $out): void
    {
        $i8 = $context->getTypeFromString('int8');
        $gPtr = self::globalPtr($context, self::G_EXCEPTION_IGNORE_ARGS, $i8);
        $oldOn = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($gPtr),
            $i8->constInt(0, false)
        );
        $oldOnBb = BasicBlockHelper::append($context, 'ini_set_ignore_old_on');
        $oldOffBb = BasicBlockHelper::append($context, 'ini_set_ignore_old_off');
        $parseBb = BasicBlockHelper::append($context, 'ini_set_ignore_parse');
        $context->builder->branchIf($oldOn, $oldOnBb, $oldOffBb);

        $context->builder->positionAtEnd($oldOnBb);
        $oldOne = $context->builder->load($context->constantStringFromString('1'));
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $oldOne);
        $context->builder->branch($parseBb);

        $context->builder->positionAtEnd($oldOffBb);
        $oldZero = $context->builder->load($context->constantStringFromString('0'));
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $oldZero);
        $context->builder->branch($parseBb);

        $context->builder->positionAtEnd($parseBb);
        $newOn = self::emitParseBoolIni($context, $valueStr);
        $context->builder->store(
            $context->builder->select($newOn, $i8->constInt(1, false), $i8->constInt(0, false)),
            $gPtr
        );
    }

    /** VmIni::parseBoolIni — thin strcmp table (empty/0/off/false → false). */
    private static function emitParseBoolIni(Context $context, Value $valueStr): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $strMap = $context->structFieldMap['__string__'];
        $cstr = $context->builder->pointerCast(
            $context->builder->structGep($valueStr, $strMap['value']),
            $i8p
        );
        $falsy = false;
        foreach (['', '0', 'off', 'false'] as $lit) {
            $want = $context->builder->load($context->constantStringFromString($lit));
            $wantCstr = $context->builder->pointerCast(
                $context->builder->structGep($want, $strMap['value']),
                $i8p
            );
            $cmp = $context->builder->call(
                $context->lookupFunction(StringCaseCompare::ABI_STRCASECMP),
                $cstr,
                $wantCstr
            );
            $eq = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $falsy = false === $falsy ? $eq : $context->builder->or($falsy, $eq);
        }

        return $context->builder->xor($falsy, $i1->constInt(1, false));
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

    /** Lazy seed — NestedJIT does not emit PHP static defaults on IniJitHelper (#11841). */
    private static function emitEnsureCompiledDefaults(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        if (null === $context->module->getNamedGlobal(self::G_DEFAULTS_SEEDED)) {
            $g = $context->module->addGlobal($i8, self::G_DEFAULTS_SEEDED);
            $g->setInitializer($i8->constInt(0, false));
        }
        $seedPtr = self::globalPtr($context, self::G_DEFAULTS_SEEDED, $i8);
        $seeded = $context->builder->load($seedPtr);
        $seedBb = BasicBlockHelper::append($context, 'ini_jit_defaults_seed');
        $doneBb = BasicBlockHelper::append($context, 'ini_jit_defaults_ready');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $seeded, $i8->constInt(0, false)),
            $seedBb,
            $doneBb
        );

        $context->builder->positionAtEnd($seedBb);
        $context->builder->call(self::helperFunction($context, self::RESET_DEFAULTS_HELPER));
        // Do NOT syncPrecisionGlobal/syncSerializePrecisionGlobal here: NestedJIT
        // getPrecisionInt after reset can return 0 and clobber the LLVM global
        // initializers (14 / -1) that thin ini_get reads (#33059).
        // ini_set/ini_restore still sync the thin globals after NestedJIT mutates.
        $context->builder->store($i8->constInt(1, false), $seedPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
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
