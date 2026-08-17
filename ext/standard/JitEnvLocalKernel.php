<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringGetenv;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_env_local_* via EnvLocalJitHelper PHP (#9814, #13431, #19809, #23211).
 *
 * Quarantined from lib/JIT/Builtin/EnvLocalRuntime — {@see \PHPCompiler\JIT\Builtin\EnvLocalRuntime}
 * stays the thin orchestrator.
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer HashContextEmbedBridge #23189).
 *
 * SSOT: {@see EnvLocalJitHelper}, {@see GetenvJitHelper}
 * php-src: ext/standard/basic_functions.c — zif_putenv, zif_getenv
 */
final class JitEnvLocalKernel
{
    private const HELPER_PATH = '/ext/standard/EnvLocalJitHelper.php';

    private const LOOKUP_HELPER = 'PHPCompiler\\ext\\standard\\EnvLocalJitHelper::lookupOverlay';

    private const REGISTER_HELPER = 'PHPCompiler\\ext\\standard\\EnvLocalJitHelper::registerPutenv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LOOKUP_HELPER,
        self::REGISTER_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_env_local_lookup',
        '__compiler_env_register_putenv',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** bootstrap-aot-link: linkable putenv/getenv ABI without nested EnvLocalJitHelper JIT (#1492). */
    public static function ensureBootstrapAotStubLinked(Context $context): void
    {
        $register = $context->module->getNamedFunction('__compiler_env_register_putenv');
        if (null !== $register && $register->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibc($context);
        self::implementBootstrapLookupStub($context);
        self::implementBootstrapRegisterStub($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    public static function implement(Context $context): void
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);

        $probe = $context->module->getNamedFunction('__compiler_env_local_lookup');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);

            return;
        }

        self::ensureLibc($context);
        self::ensureJitHelperCompiled($context);
        self::implementLookupBridge($context);
        self::implementRegisterBridge($context);
        self::registerLinkedRuntime($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
    }

    private static function implementBootstrapLookupStub(Context $context): void
    {
        $abiName = '__compiler_env_local_lookup';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($i8p, false, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('el_bootstrap_lookup');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($i8p->constNull());
        $context->registerFunction($abiName, $fn);
    }

    private static function implementBootstrapRegisterStub(Context $context): void
    {
        $abiName = '__compiler_env_register_putenv';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('el_bootstrap_reg_entry');
        $skipBb = $fn->appendBasicBlock('el_bootstrap_reg_skip');
        $bodyBb = $fn->appendBasicBlock('el_bootstrap_reg_body');
        $context->builder->positionAtEnd($entry);

        $settingCstr = $fn->getParam(0);
        $null = $i8p->constNull();
        $settingNull = $context->builder->icmp(Builder::INT_EQ, $settingCstr, $null);
        $context->builder->branchIf($settingNull, $skipBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        self::ensureExternal(
            $context,
            'putenv',
            $context->context->functionType($i32, false, $i8p)
        );
        $context->builder->call($context->lookupFunction('putenv'), $settingCstr);
        $context->builder->branch($skipBb);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementLookupBridge(Context $context): void
    {
        $abiName = '__compiler_env_local_lookup';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($i8p, false, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $null = $i8p->constNull();
        $entry = $fn->appendBasicBlock('el_lookup_entry');
        $context->builder->positionAtEnd($entry);

        $nameCstr = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');

        $missBb = $fn->appendBasicBlock('el_lookup_miss');
        $bodyBb = $fn->appendBasicBlock('el_lookup_body');
        $nameNull = $context->builder->icmp(Builder::INT_EQ, $nameCstr, $null);
        $context->builder->branchIf($nameNull, $missBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $nameLen = $context->builder->call($context->lookupFunction('strlen'), $nameCstr);
        $nameLenI64 = $nameLen->typeOf() === $i64
            ? $nameLen
            : $context->builder->zExt($nameLen, $i64);
        $nameStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $nameLenI64,
            $nameCstr
        );
        $overlayRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LOOKUP_HELPER),
            [$nameStr]
        );
        $isMiss = JitNestedHelperCoerce::isHelperResultNull($context, $overlayRaw);
        $hitBb = $fn->appendBasicBlock('el_lookup_hit');
        $context->builder->branchIf($isMiss, $missBb, $hitBb);

        $context->builder->positionAtEnd($hitBb);
        $overlayPtr = JitNestedHelperCoerce::valueBoxPtrFromHelperResult($context, $overlayRaw);
        $valueStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $overlayPtr
        );
        $dup = self::dupCstrFromStringStruct($context, $valueStr);
        $doneBb = $fn->appendBasicBlock('el_lookup_done');
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->returnValue($null);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($dup);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementRegisterBridge(Context $context): void
    {
        $abiName = '__compiler_env_register_putenv';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('el_reg_entry');
        $skipBb = $fn->appendBasicBlock('el_reg_skip');
        $bodyBb = $fn->appendBasicBlock('el_reg_body');
        $context->builder->positionAtEnd($entry);

        $settingCstr = $fn->getParam(0);
        $null = $i8p->constNull();
        $settingNull = $context->builder->icmp(Builder::INT_EQ, $settingCstr, $null);
        $context->builder->branchIf($settingNull, $skipBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call($context->lookupFunction('strlen'), $settingCstr);
        $lenI64 = $len->typeOf() === $i64
            ? $len
            : $context->builder->zExt($len, $i64);
        $settingStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $settingCstr
        );
        $context->builder->call(
            self::helperFunction($context, self::REGISTER_HELPER),
            $settingStr
        );
        $context->builder->branch($skipBb);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    /** Duplicate __string__ payload bytes into a malloc'd C string (#12910). */
    private static function dupCstrFromStringStruct(Context $context, Value $src): Value
    {
        $strMap = $context->structFieldMap['__string__'];
        $valueBytes = $context->builder->structGep($src, $strMap['value']);

        return self::dupCstrBytes($context, $valueBytes);
    }

    private static function dupCstrBytes(Context $context, Value $src): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $len = $context->builder->call($context->lookupFunction('strlen'), $src);
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->add($len, $sizeT->constInt(1, false))
        );
        $dest = $context->builder->pointerCast($buf, $i8p);
        $context->builder->call($context->lookupFunction('memcpy'), $dest, $src, $len);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($dest, $len)
        );

        return $dest;
    }

    private static function ensureLibc(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach ([
            ['strlen', $i64, [$i8p]],
            ['malloc', $voidPtr, [$sizeT]],
            ['__string__init', $context->getTypeFromString('__string__*'), [$i64, $i8p]],
            ['__value__readString', $context->getTypeFromString('__string__*'), [$context->getTypeFromString('__value__*')]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
        // memcpy(3) via LibcExtern::ensureMemcpyDecl after always-on drop (#31885);
        // canonical i8* ABI avoids void* NestedJIT mistyped calls (#27663).
        LibcExtern::ensureMemcpyDecl($context);
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

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23211');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        StringGetenv::ensureJitHelperCompiled($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23211'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after JitEnvLocalKernel bridge (#9814)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
