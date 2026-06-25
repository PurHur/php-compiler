<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Progress;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Standalone AOT LLVM for CLI $argc/$argv when nested PHP helper compile is unsafe (#9439, #11142).
 *
 * Quarantines __hashtable__alloc argv refresh; JIT embed uses {@see CliArgvRuntime} PHP bridge.
 * php-src: ext/standard/basic_functions.c — $argc / $argv in CLI SAPI
 */
final class CliArgvStandaloneLlvm
{
    private const G_ARGC = 'phpc_cli_argc_global';

    private const G_ARGV = 'phpc_cli_argv_global';

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__phpc_cli_store_argv',
        '__phpc_cli_argc',
        '__phpc_cli_argv_cstr',
        '__phpc_cli_str_eq',
        '__phpc_cli_refresh_argv_global',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_cli_refresh_argv_global');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureGlobals($context);
        self::ensureExternals($context);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');

        self::implementStoreArgv($context, self::declareAbi(
            $context,
            '__phpc_cli_store_argv',
            $context->context->functionType($voidTy, false, $i32, $i8pp)
        ));
        self::implementArgc($context, self::declareAbi(
            $context,
            '__phpc_cli_argc',
            $context->context->functionType($i64, false)
        ));
        self::implementArgvCstr($context, self::declareAbi(
            $context,
            '__phpc_cli_argv_cstr',
            $context->context->functionType($i8p, false, $i32)
        ));
        self::implementStrEq($context, self::declareAbi(
            $context,
            '__phpc_cli_str_eq',
            $context->context->functionType($i32, false, $i8p, $i8p)
        ));
        self::implementRefreshArgvGlobal($context, self::declareAbi(
            $context,
            '__phpc_cli_refresh_argv_global',
            $context->context->functionType($voidTy, false, $valuePtr)
        ));

        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    /** Packed argv list from phpc_cli_* globals (getopt JIT standalone, #3251). */
    public static function buildArgvHashtable(Context $context): Value
    {
        self::implement($context);

        return self::emitFillArgvTableFromGlobals($context, BasicBlockHelper::parentFunction($context));
    }

    private static function declareAbi(Context $context, string $name, $ft): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return $probe;
        }

        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8pp = $context->getTypeFromString('int8**');

        if (null === $context->module->getNamedGlobal(self::G_ARGC)) {
            $g = $context->module->addGlobal($i32, self::G_ARGC);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_ARGV)) {
            $g = $context->module->addGlobal($i8pp, self::G_ARGV);
            $g->setInitializer($i8pp->constNull());
        }
    }

    private static function globalPtr(Context $context, string $name, $type): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('CliArgvStandaloneLlvm global missing: '.$name);
        }

        return $context->builder->pointerCast($global, $type->pointerType(0));
    }

    private static function implementStoreArgv(Context $context, LlvmFunction $fn): void
    {
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('cli_store_entry');
        $context->builder->positionAtEnd($entry);

        $argc = $fn->getParam(0);
        $argv = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i8pp = $context->getTypeFromString('int8**');

        $context->builder->store($argc, self::globalPtr($context, self::G_ARGC, $i32));
        $context->builder->store($argv, self::globalPtr($context, self::G_ARGV, $i8pp));
        Progress::emitNativeNote($context, 'c:cli_store_argv');
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementArgc(Context $context, LlvmFunction $fn): void
    {
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('cli_argc_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $loaded = $context->builder->load(self::globalPtr($context, self::G_ARGC, $i32));
        $context->builder->returnValue($context->builder->sext($loaded, $i64));
        $context->builder->clearInsertionPosition();
    }

    private static function implementArgvCstr(Context $context, LlvmFunction $fn): void
    {
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('cli_cstr_entry');
        $context->builder->positionAtEnd($entry);

        $index = $fn->getParam(0);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');

        $nullBb = $fn->appendBasicBlock('cli_cstr_null');
        $okBb = $fn->appendBasicBlock('cli_cstr_ok');
        $doneBb = $fn->appendBasicBlock('cli_cstr_done');

        $argc = $context->builder->load(self::globalPtr($context, self::G_ARGC, $i32));
        $argvPtr = $context->builder->load(self::globalPtr($context, self::G_ARGV, $i8pp));
        $nullArgv = $context->builder->icmp(Builder::INT_EQ, $argvPtr, $i8pp->constNull());
        $negIndex = $context->builder->icmp(Builder::INT_SLT, $index, $i32->constInt(0, false));
        $geArgc = $context->builder->icmp(Builder::INT_SGE, $index, $argc);
        $bad = $context->builder->or($nullArgv, $context->builder->or($negIndex, $geArgc));
        $context->builder->branchIf($bad, $nullBb, $okBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $elemPtr = $context->builder->gep($argvPtr, $index);
        $elem = $context->builder->load($elemPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $result = $context->builder->phi($i8p);
        $result->addIncoming($i8p->constNull(), $nullBb);
        $result->addIncoming($elem, $okBb);
        $context->builder->returnValue($result);
        $context->builder->clearInsertionPosition();
    }

    private static function implementStrEq(Context $context, LlvmFunction $fn): void
    {
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('cli_eq_entry');
        $context->builder->positionAtEnd($entry);

        $a = $fn->getParam(0);
        $b = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        $nullBb = $fn->appendBasicBlock('cli_eq_null');
        $cmpBb = $fn->appendBasicBlock('cli_eq_cmp');
        $doneBb = $fn->appendBasicBlock('cli_eq_done');

        $aNull = $context->builder->icmp(Builder::INT_EQ, $a, $i8p->constNull());
        $bNull = $context->builder->icmp(Builder::INT_EQ, $b, $i8p->constNull());
        $eitherNull = $context->builder->or($aNull, $bNull);
        $context->builder->branchIf($eitherNull, $nullBb, $cmpBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($cmpBb);
        $cmp = $context->builder->call($context->lookupFunction('strcmp'), $a, $b);
        $isEq = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $eqVal = $context->builder->select(
            $isEq,
            $i32->constInt(1, false),
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $result = $context->builder->phi($i32);
        $result->addIncoming($i32->constInt(0, false), $nullBb);
        $result->addIncoming($eqVal, $cmpBb);
        $context->builder->returnValue($result);
        $context->builder->clearInsertionPosition();
    }

    private static function implementRefreshArgvGlobal(Context $context, LlvmFunction $fn): void
    {
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('cli_refresh_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $valuePtr = $context->getTypeFromString('__value__*');

        Progress::emitNativeNote($context, 'c:cli_refresh_argv_begin');

        $nullOutBb = $fn->appendBasicBlock('cli_refresh_null_out');
        $bodyBb = $fn->appendBasicBlock('cli_refresh_body');
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $context->builder->branchIf($outNull, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $ht = self::emitFillArgvTableFromGlobals($context, $fn);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $out, $ht);
        Progress::emitNativeNote($context, 'c:cli_refresh_argv_done');
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitFillArgvTableFromGlobals(Context $context, LlvmFunction $fn): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));

        $argc = $context->builder->load(self::globalPtr($context, self::G_ARGC, $i32));
        $iSlot = $context->builder->alloca($i32, 1, 'cli_refresh_i');
        $context->builder->store($i32->constInt(0, false), $iSlot);

        $loopHeadBb = $fn->appendBasicBlock('cli_refresh_loop_head');
        $context->builder->branch($loopHeadBb);

        $context->builder->positionAtEnd($loopHeadBb);
        $i = $context->builder->load($iSlot);
        $doneLoop = $context->builder->icmp(Builder::INT_SGE, $i, $argc);
        $loopDoneBb = $fn->appendBasicBlock('cli_refresh_loop_done');
        $loopBodyBb = $fn->appendBasicBlock('cli_refresh_loop_body');
        $context->builder->branchIf($doneLoop, $loopDoneBb, $loopBodyBb);

        $context->builder->positionAtEnd($loopBodyBb);
        $cstr = $context->builder->call($context->lookupFunction('__phpc_cli_argv_cstr'), $i);
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($len, $i64),
            $cstr
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $ht,
            $context->builder->zext($i, $sizeT),
            $str
        );
        $context->builder->store(
            $context->builder->add($i, $i32->constInt(1, false)),
            $iSlot
        );
        $context->builder->branch($loopHeadBb);

        $context->builder->positionAtEnd($loopDoneBb);

        return $ht;
    }

    private static function ensureExternals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');

        foreach (
            [
                'strcmp' => [$i32, false, [$i8p, $i8p]],
                'strlen' => [$sizeT, false, [$i8p]],
                '__hashtable__alloc' => [$htPtr, false, []],
                '__hashtable__setStringAt' => [$voidTy, false, [$htPtr, $sizeT, $strPtr]],
                '__string__init' => [$strPtr, false, [$i64, $i8p]],
                '__value__writeHashtable' => [$voidTy, false, [$valuePtr, $htPtr]],
            ] as $name => [$ret, $vararg, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, $vararg, ...$params));
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
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after CliArgvStandaloneLlvm implement (#9439)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
