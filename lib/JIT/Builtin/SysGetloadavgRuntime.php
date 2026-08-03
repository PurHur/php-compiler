<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_sys_getloadavg via SysGetloadavgJitHelper PHP (#12106, #22399, #27294).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer GethostbyaddrRuntime #22370).
 * Thin AOT: NestedJIT HashTable return is not a real `__hashtable__*` — materialize via
 * `__hashtable__alloc` + `__hashtable__setDoubleAt` from NestedJIT scalars (peer gc_status #26943).
 * SSOT: VmSys::getLoadavg. php-src: ext/standard/basic_functions.c — PHP_FUNCTION(sys_getloadavg)
 */
final class SysGetloadavgRuntime
{
    private const ABI_NAME = '__compiler_sys_getloadavg';

    private const HELPER_PATH = '/ext/standard/SysGetloadavgJitHelper.php';

    private const RESOLVE_OK = 'PHPCompiler\\ext\\standard\\SysGetloadavgJitHelper::resolveOk';

    private const LOAD_AT = 'PHPCompiler\\ext\\standard\\SysGetloadavgJitHelper::loadAt';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESOLVE_OK,
        self::LOAD_AT,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            self::declareAbiForNestedJit($context);

            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureExternals($context);
        self::ensureJitHelperCompiled($context);
        self::implementBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function declareAbiForNestedJit(Context $context): void
    {
        try {
            $context->lookupFunction(self::ABI_NAME);
        } catch (\Throwable) {
            $voidTy = $context->getTypeFromString('void');
            $valuePtr = $context->getTypeFromString('__value__*');
            $context->registerFunction(
                self::ABI_NAME,
                $context->module->addFunction(
                    self::ABI_NAME,
                    $context->context->functionType($voidTy, false, $valuePtr)
                )
            );
        }
    }

    private static function implementBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $double = $context->getTypeFromString('double');
        $ft = $context->context->functionType($voidTy, false, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('sgla_bridge_entry');
        $nullOutBb = $fn->appendBasicBlock('sgla_bridge_null_out');
        $bodyBb = $fn->appendBasicBlock('sgla_bridge_body');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $context->builder->branchIf($outNull, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $okWide = $context->builder->call(self::helperFunction($context, self::RESOLVE_OK));
        $ok = $context->builder->trunc($okWide, $i32);
        $okFlag = $context->builder->icmp(Builder::INT_NE, $ok, $i32->constInt(0, false));
        $failBb = $fn->appendBasicBlock('sgla_bridge_fail');
        $okBb = $fn->appendBasicBlock('sgla_bridge_ok');
        $context->builder->branchIf($okFlag, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($okBb);
        $ht = HashTableHelper::alloc($context);
        $setDouble = $context->lookupFunction('__hashtable__setDoubleAt');
        $loadAt = self::helperFunction($context, self::LOAD_AT);
        for ($i = 0; $i < 3; ++$i) {
            $d = $context->builder->call($loadAt, $i64->constInt($i, false));
            $context->builder->call(
                $setDouble,
                $ht,
                $sizeT->constInt($i, false),
                $d
            );
        }
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $out,
            $ht
        );
        $context->builder->returnVoid();
        $context->registerFunction(self::ABI_NAME, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22399');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22399'
        );
    }

    private static function ensureExternals(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i32 = $context->getTypeFromString('int32');

        // Do NOT declare empty stubs for __hashtable__* / setDoubleAt — that masks the
        // HashTable builtin / helper-runtime bodies and leaves float slots at 0 (#27294).
        foreach ([
            ['__value__writeBool', $voidTy, [$valuePtr, $i32]],
            ['__value__writeHashtable', $voidTy, [$valuePtr, $htPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal(
                $context,
                $name,
                $context->context->functionType($ret, false, ...$params)
            );
        }
        // Ensure packed HT mutators exist before the bridge emits calls (#27294 / peer RangeInt).
        try {
            $context->lookupFunction('__hashtable__alloc');
            $context->lookupFunction('__hashtable__setDoubleAt');
        } catch (\Throwable $e) {
            throw new \LogicException(
                'SysGetloadavgRuntime requires __hashtable__alloc/setDoubleAt (#27294): '.$e->getMessage(),
                0,
                $e
            );
        }
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

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after SysGetloadavgRuntime bridge (#12106)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
