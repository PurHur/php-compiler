<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_sys_get_temp_dir (#9585, #26929).
 *
 * Thin AOT NestedJIT of SysGetTempDirJitHelper segfaults after c:main_before_php
 * (peer getdate / StreamSync / getmypid). Emit getenv(TMPDIR|TEMP|TMP) → realpath
 * → __string__ in LLVM; VM SSOT stays {@see \PHPCompiler\ext\standard\VmSysGetTempDirPure}.
 * php-src: ext/standard/file.c — PHP_FUNCTION(sys_get_temp_dir)
 */
final class SysGetTempDirRuntime
{
    private const PATH_MAX = 4096;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_sys_get_temp_dir',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_sys_get_temp_dir');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureLibc($context);
        self::implementIfMissing($context, '__compiler_sys_get_temp_dir', self::emitResolve(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
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

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $strPtr = $context->getTypeFromString('__string__*');

        return $context->module->addFunction(
            $name,
            $context->context->functionType($strPtr, false)
        );
    }

    private static function ensureLibc(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        foreach ([
            ['getenv', $i8p, [$i8p]],
            ['realpath', $i8p, [$i8p, $i8p]],
            ['strlen', $context->getTypeFromString('int64'), [$i8p]],
        ] as [$name, $ret, $params]) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function emitResolve(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sys_get_temp_dir_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $dirSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store(self::literalCstr($context, '/tmp'), $dirSlot);

        $checkTmpdir = $fn->appendBasicBlock('tmpdir_check_tmpdir');
        $checkTmpdirEmpty = $fn->appendBasicBlock('tmpdir_check_tmpdir_empty');
        $checkTemp = $fn->appendBasicBlock('tmpdir_check_temp');
        $checkTempEmpty = $fn->appendBasicBlock('tmpdir_check_temp_empty');
        $checkTmp = $fn->appendBasicBlock('tmpdir_check_tmp');
        $checkTmpEmpty = $fn->appendBasicBlock('tmpdir_check_tmp_empty');
        $resolve = $fn->appendBasicBlock('tmpdir_resolve');
        $context->builder->branch($checkTmpdir);

        $context->builder->positionAtEnd($checkTmpdir);
        $tmpdir = $context->builder->call($context->lookupFunction('getenv'), self::literalCstr($context, 'TMPDIR'));
        $tmpdirOk = $context->builder->icmp(Builder::INT_NE, $tmpdir, $i8p->constNull());
        $useTmpdir = $fn->appendBasicBlock('tmpdir_use_tmpdir');
        $context->builder->branchIf($tmpdirOk, $checkTmpdirEmpty, $checkTemp);
        $context->builder->positionAtEnd($checkTmpdirEmpty);
        $tmpdirNotEmpty = $context->builder->icmp(Builder::INT_NE, $context->builder->load($tmpdir), $i8->constInt(0, false));
        $context->builder->branchIf($tmpdirNotEmpty, $useTmpdir, $checkTemp);
        $context->builder->positionAtEnd($useTmpdir);
        $context->builder->store($tmpdir, $dirSlot);
        $context->builder->branch($resolve);

        $context->builder->positionAtEnd($checkTemp);
        $temp = $context->builder->call($context->lookupFunction('getenv'), self::literalCstr($context, 'TEMP'));
        $tempOk = $context->builder->icmp(Builder::INT_NE, $temp, $i8p->constNull());
        $useTemp = $fn->appendBasicBlock('tmpdir_use_temp');
        $context->builder->branchIf($tempOk, $checkTempEmpty, $checkTmp);
        $context->builder->positionAtEnd($checkTempEmpty);
        $tempNotEmpty = $context->builder->icmp(Builder::INT_NE, $context->builder->load($temp), $i8->constInt(0, false));
        $context->builder->branchIf($tempNotEmpty, $useTemp, $checkTmp);
        $context->builder->positionAtEnd($useTemp);
        $context->builder->store($temp, $dirSlot);
        $context->builder->branch($resolve);

        $context->builder->positionAtEnd($checkTmp);
        $tmp = $context->builder->call($context->lookupFunction('getenv'), self::literalCstr($context, 'TMP'));
        $tmpOk = $context->builder->icmp(Builder::INT_NE, $tmp, $i8p->constNull());
        $useTmp = $fn->appendBasicBlock('tmpdir_use_tmp');
        $context->builder->branchIf($tmpOk, $checkTmpEmpty, $resolve);
        $context->builder->positionAtEnd($checkTmpEmpty);
        $tmpNotEmpty = $context->builder->icmp(Builder::INT_NE, $context->builder->load($tmp), $i8->constInt(0, false));
        $context->builder->branchIf($tmpNotEmpty, $useTmp, $resolve);
        $context->builder->positionAtEnd($useTmp);
        $context->builder->store($tmp, $dirSlot);
        $context->builder->branch($resolve);

        $context->builder->positionAtEnd($resolve);
        $resolvedSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::PATH_MAX));
        $resolved = $context->builder->pointerCast($resolvedSlot, $i8p);
        $useDir = $context->builder->load($dirSlot);
        $real = $context->builder->call($context->lookupFunction('realpath'), $useDir, $resolved);
        $hasReal = $context->builder->icmp(Builder::INT_NE, $real, $i8p->constNull());
        $retReal = $fn->appendBasicBlock('tmpdir_ret_real');
        $retDir = $fn->appendBasicBlock('tmpdir_ret_dir');
        $context->builder->branchIf($hasReal, $retReal, $retDir);

        $context->builder->positionAtEnd($retReal);
        $context->builder->returnValue(self::cstrToString($context, $resolved));

        $context->builder->positionAtEnd($retDir);
        $context->builder->returnValue(self::cstrToString($context, $useDir));
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast($context->constantFromString($text), $i8p);
    }

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $context->builder->pointerCast($cstr, $charPtr)
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after SysGetTempDirRuntime bridge (#9585)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
