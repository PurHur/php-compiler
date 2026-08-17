<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Progress;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for CLI $argc/$argv via honest refresh + VmCliArgv PHP (#9439, #20904).
 *
 * Embed + thin standalone AOT: ABI stubs then real `__phpc_cli_refresh_argv_global`
 * (no void-refresh fork). Empty argv tables use `__hashtable__alloc` (DefineRuntime /
 * SuperglobalInit shape) — NestedJIT `new HashTable()` segfaults in user-script AOT.
 * Thin init links via {@see Context::ensureMinimalUserStandaloneBodies} before {main} $argc lowering.
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmCliArgv} / {@see \PHPCompiler\ext\standard\CliArgvJitHelper}
 * php-src: ext/standard/basic_functions.c — $argc / $argv in CLI SAPI
 */
final class CliArgvRuntime
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
        $probe = $context->module->getNamedFunction('__phpc_cli_refresh_argv_global');
        // Real NestedJIT refresh has multiple blocks; ignore void stub leftovers.
        if (null !== $probe && $probe->countBasicBlocks() > 1) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureGlobals($context);
        self::ensureExternals($context);

        // CLI argv ABI before nested CliArgvJitHelper compile — nested JIT during
        // bridge emission corrupts the parent insert block (LLVM 9; #8559, #14470).
        self::implementStoreArgv($context, self::declareAbi($context, '__phpc_cli_store_argv', $context->context->functionType(
            $context->getTypeFromString('void'),
            false,
            $context->getTypeFromString('int32'),
            $context->getTypeFromString('int8**')
        )));
        self::implementArgc($context, self::declareAbi($context, '__phpc_cli_argc', $context->context->functionType(
            $context->getTypeFromString('int64'),
            false
        )));
        self::implementArgvCstr($context, self::declareAbi($context, '__phpc_cli_argv_cstr', $context->context->functionType(
            $context->getTypeFromString('int8*'),
            false,
            $context->getTypeFromString('int32')
        )));
        self::implementStrEq($context, self::declareAbi($context, '__phpc_cli_str_eq', $context->context->functionType(
            $context->getTypeFromString('int32'),
            false,
            $context->getTypeFromString('int8*'),
            $context->getTypeFromString('int8*')
        )));

        self::implementRefreshArgvGlobal($context, self::declareAbi($context, '__phpc_cli_refresh_argv_global', $context->context->functionType(
            $context->getTypeFromString('void'),
            false,
            $context->getTypeFromString('__value__*')
        )));

        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    /** Packed argv list from phpc_cli_* globals (getopt JIT, #3251). */
    public static function buildArgvHashtable(Context $context): Value
    {
        self::ensureLinked($context);

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
            throw new \LogicException('CliArgvRuntime global missing: '.$name);
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
        // strcmp(3) via LibcExtern::ensureStrcmpDecl after always-on drop (#31971).
        LibcExtern::ensureStrcmpDecl($context);
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
        // NestedJIT refresh has multiple blocks; a single-block void stub must not win (#20904).
        if ($fn->countBasicBlocks() > 1) {
            return;
        }
        if ($fn->countBasicBlocks() === 1) {
            throw new \LogicException(
                '__phpc_cli_refresh_argv_global already has a void stub body; use a fresh JIT Context (#20904)'
            );
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
        $iSlot = $context->builder->alloca($i32, 1, 'cli_argv_i');
        $context->builder->store($i32->constInt(0, false), $iSlot);

        $loopHead = $fn->appendBasicBlock('cli_argv_loop_head');
        $loopDone = $fn->appendBasicBlock('cli_argv_loop_done');
        $loopBody = $fn->appendBasicBlock('cli_argv_loop_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $done = $context->builder->icmp(Builder::INT_SGE, $i, $argc);
        $context->builder->branchIf($done, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
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
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);

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

        // strcmp(3) after always-on LibcExtern drop (#31971).
        LibcExtern::ensureStrcmpDecl($context);
        foreach (
            [
                'strcmp' => [$i32, false, [$i8p, $i8p]],
                'strlen' => [$sizeT, false, [$i8p]],
                '__string__init' => [$strPtr, false, [$i64, $i8p]],
                '__hashtable__alloc' => [$htPtr, false, []],
                '__hashtable__setStringAt' => [$voidTy, false, [$htPtr, $sizeT, $strPtr]],
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
                throw new \LogicException($name.' missing after CliArgvRuntime bridge (#9439)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
