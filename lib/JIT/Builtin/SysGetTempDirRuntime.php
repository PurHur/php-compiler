<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for sys_get_temp_dir() via SysGetTempDirJitHelper PHP (#29433, #9585, #26929).
 *
 * Embed + thin standalone AOT: {@see SysGetTempDirJitHelper} via {@see JitVmHelperLink}
 * (getcwd #29429 / gethostname #29364 / microtime #29405 shape — no always-on libc fork).
 * Nested helper compile: `@sys_get_temp_dir` → thin getenv/realpath leaf without re-entering
 * SysGetTempDirJitHelper (former always-on libc LLVM #26929).
 * SSOT for VM: {@see \PHPCompiler\ext\standard\VmSysGetTempDirNative}.
 * php-src: ext/standard/file.c — PHP_FUNCTION(sys_get_temp_dir)
 */
final class SysGetTempDirRuntime
{
    private const ABI = '__compiler_sys_get_temp_dir';

    /** Module-local libc getenv/realpath body — NestedJIT leaf (#29433). */
    private const NESTED_LEAF_ABI = '__compiler_sys_get_temp_dir_leaf';

    private const HELPER_PATH = '/ext/standard/SysGetTempDirJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\SysGetTempDirJitHelper::resolveJit';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'sys_get_temp_dir_bridge_entry';

    private const PATH_MAX = 4096;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        self::ABI,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /** @return Value `__string__*` — always non-empty (php-src falls back to /tmp) */
    public static function invoke(Context $context): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return self::invokeNestedLeaf($context);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI));
    }

    /** @return Value `__string__*` — libc getenv/realpath NestedJIT leaf (#29433) */
    public static function invokeNestedLeaf(Context $context): Value
    {
        self::ensureNestedLeafBody($context);

        return $context->builder->call($context->lookupFunction(self::NESTED_LEAF_ABI));
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [],
            $strPtr,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#29433'
        );
        self::registerLinkedRuntime($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    private static function ensureNestedLeafBody(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::NESTED_LEAF_ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::NESTED_LEAF_ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureLibc($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::NESTED_LEAF_ABI,
                $context->context->functionType($strPtr, false)
            );
        self::emitResolve($context, $fn);
        $context->registerFunction(self::NESTED_LEAF_ABI, $fn);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    /**
     * Module-local getenv/realpath/strlen after LibcExtern always-on drops
     * (#31534 realpath; #31637 getenv — peer StringGetenv::ensureLibcGetenv).
     */
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
        $entry = $fn->appendBasicBlock('sys_get_temp_dir_leaf_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $dirSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store(self::literalCstr($context, '/tmp'), $dirSlot);

        $checkTmpdir = $fn->appendBasicBlock('tmpdir_leaf_check_tmpdir');
        $checkTmpdirEmpty = $fn->appendBasicBlock('tmpdir_leaf_check_tmpdir_empty');
        $checkTemp = $fn->appendBasicBlock('tmpdir_leaf_check_temp');
        $checkTempEmpty = $fn->appendBasicBlock('tmpdir_leaf_check_temp_empty');
        $checkTmp = $fn->appendBasicBlock('tmpdir_leaf_check_tmp');
        $checkTmpEmpty = $fn->appendBasicBlock('tmpdir_leaf_check_tmp_empty');
        $resolve = $fn->appendBasicBlock('tmpdir_leaf_resolve');
        $context->builder->branch($checkTmpdir);

        $context->builder->positionAtEnd($checkTmpdir);
        $tmpdir = $context->builder->call($context->lookupFunction('getenv'), self::literalCstr($context, 'TMPDIR'));
        $tmpdirOk = $context->builder->icmp(Builder::INT_NE, $tmpdir, $i8p->constNull());
        $useTmpdir = $fn->appendBasicBlock('tmpdir_leaf_use_tmpdir');
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
        $useTemp = $fn->appendBasicBlock('tmpdir_leaf_use_temp');
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
        $useTmp = $fn->appendBasicBlock('tmpdir_leaf_use_tmp');
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
        $retReal = $fn->appendBasicBlock('tmpdir_leaf_ret_real');
        $retDir = $fn->appendBasicBlock('tmpdir_leaf_ret_dir');
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
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
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
                throw new \LogicException($name.' missing after SysGetTempDirRuntime bridge (#29433)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
