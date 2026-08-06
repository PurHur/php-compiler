<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_getrusage via GetrusageJitHelper PHP (#9184, #25754, #27551).
 *
 * Thin AOT: NestedJIT HashTable return is not a real `__hashtable__*` — materialize via
 * `__hashtable__alloc` + `__hashtable__setStringKeyLong` from NestedJIT scalars
 * (peer gc_status #26943 / sys_getloadavg #27294).
 * SSOT: VmGetrusageNative. php-src: ext/standard/basic_functions.c — PHP_FUNCTION(getrusage)
 */
final class StringGetrusageRuntime
{
    private const ABI_NAME = '__compiler_getrusage';

    private const HELPER_PATH = '/ext/standard/GetrusageJitHelper.php';

    private const RESOLVE_OK = 'PHPCompiler\\ext\\standard\\GetrusageJitHelper::resolveOk';

    private const VALUE_AT = 'PHPCompiler\\ext\\standard\\GetrusageJitHelper::valueAt';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESOLVE_OK,
        self::VALUE_AT,
    ];

    /**
     * Must match {@see \PHPCompiler\ext\standard\GetrusageJitHelper::KEYS}.
     *
     * @var list<string>
     */
    private const KEYS = [
        'ru_oublock',
        'ru_inblock',
        'ru_msgsnd',
        'ru_msgrcv',
        'ru_maxrss',
        'ru_ixrss',
        'ru_idrss',
        'ru_minflt',
        'ru_majflt',
        'ru_nsignals',
        'ru_nvcsw',
        'ru_nivcsw',
        'ru_nswap',
        'ru_utime.tv_usec',
        'ru_utime.tv_sec',
        'ru_stime.tv_usec',
        'ru_stime.tv_sec',
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
        self::implementGetrusageBridge($context);
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
            $i64 = $context->getTypeFromString('int64');
            $valuePtr = $context->getTypeFromString('__value__*');
            $context->registerFunction(
                self::ABI_NAME,
                $context->module->addFunction(
                    self::ABI_NAME,
                    $context->context->functionType($voidTy, false, $i64, $valuePtr)
                )
            );
        }
    }

    private static function implementGetrusageBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($voidTy, false, $i64, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('gr_bridge_entry');
        $nullOutBb = $fn->appendBasicBlock('gr_bridge_null_out');
        $bodyBb = $fn->appendBasicBlock('gr_bridge_body');
        $context->builder->positionAtEnd($entry);

        $who = $fn->getParam(0);
        $out = $fn->getParam(1);
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $context->builder->branchIf($outNull, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $okWide = $context->builder->call(self::helperFunction($context, self::RESOLVE_OK), $who);
        $ok = $context->builder->trunc($okWide, $i32);
        $okFlag = $context->builder->icmp(Builder::INT_NE, $ok, $i32->constInt(0, false));
        $failBb = $fn->appendBasicBlock('gr_bridge_fail');
        $okBb = $fn->appendBasicBlock('gr_bridge_ok');
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
        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $valueAt = self::helperFunction($context, self::VALUE_AT);
        foreach (self::KEYS as $i => $key) {
            $v = $context->builder->call(
                $valueAt,
                $who,
                $i64->constInt($i, false)
            );
            $context->builder->call(
                $setLong,
                $ht,
                self::literalKeyString($context, $key),
                $v
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

    private static function literalKeyString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $context->builder->pointerCast($context->constantFromString($text), $charPtr)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25754');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25754'
        );
    }

    private static function ensureExternals(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i32 = $context->getTypeFromString('int32');

        // Do NOT declare empty stubs for __hashtable__* / __string__init — that masks the
        // HashTable builtin / helper-runtime bodies (#27294 / #27551).
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
        try {
            $context->lookupFunction('__hashtable__alloc');
            $context->lookupFunction('__hashtable__setStringKeyLong');
            $context->lookupFunction('__string__init');
        } catch (\Throwable $e) {
            throw new \LogicException(
                'StringGetrusageRuntime requires __hashtable__alloc/setStringKeyLong/__string__init (#27551): '.$e->getMessage(),
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
            throw new \LogicException(self::ABI_NAME.' missing after StringGetrusageRuntime bridge (#9184)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
