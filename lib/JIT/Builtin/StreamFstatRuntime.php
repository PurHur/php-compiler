<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamIoKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_fstat via FstatJitHelper PHP (#10460, #24586, #33370).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer MathModf #22519) for VM
 * resource handles. Thin AOT: NestedJIT VmFs cannot see
 * {@see JitStreamIoKernel} FILE* handles and NestedJIT HashTable returns abort — force
 * resolve→fileno→libc fstat(2)→hashtable (peer flock/ftell #33122; layout
 * {@see \PHPCompiler\ext\standard\JitStatKernel}).
 * SSOT: {@see \PHPCompiler\ext\standard\VmStreamFstat}, {@see \PHPCompiler\ext\standard\VmFs}
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(fstat)
 */
final class StreamFstatRuntime
{
    private const HELPER_PATH = '/ext/standard/FstatJitHelper.php';

    private const FSTAT_HELPER = 'PHPCompiler\\ext\\standard\\FstatJitHelper::fstatArgv';

    /** sizeof(struct stat) on Linux x86_64 glibc — peer JitStatKernel */
    private const STAT_BUF_SIZE = 144;

    /**
     * php-src filestat keys + glibc x86_64 offsets/widths (4 or 8).
     *
     * @var list<array{0: string, 1: int, 2: int, 3: int}>
     */
    private const STAT_FIELDS = [
        // key, index, byte offset, load width
        ['dev', 0, 0, 8],
        ['ino', 1, 8, 8],
        ['mode', 2, 24, 4],
        ['nlink', 3, 16, 8],
        ['uid', 4, 28, 4],
        ['gid', 5, 32, 4],
        ['rdev', 6, 40, 8],
        ['size', 7, 48, 8],
        ['atime', 8, 72, 8],
        ['mtime', 9, 88, 8],
        ['ctime', 10, 104, 8],
        ['blksize', 11, 56, 8],
        ['blocks', 12, 64, 8],
    ];

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FSTAT_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_fstat',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_fstat');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            if ($context->isThinStandaloneAotMain()) {
                self::forceLibcFstat($context);
            }

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementFstatBridge($context);
        self::registerLinkedRuntime($context);
        if ($context->isThinStandaloneAotMain()) {
            self::forceLibcFstat($context);
        }
        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Thin AOT: replace NestedJIT VmFs bridge with libc FILE* → fileno → fstat(2) (#33370).
     *
     * Call after StreamIo/StreamRead libc forces so resolve/fileno decls exist.
     * Do not NestedJIT a fd→stat helper — return-type / HashTable NestedJIT aborts under thin AOT.
     */
    public static function forceLibcFstat(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_fstat');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach ($probe->getBasicBlocks() as $bb) {
                if ('fstat_libc_entry' === $bb->getName()) {
                    $context->registerFunction('__compiler_fstat', $probe);

                    return;
                }
                break;
            }
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();

        // Ensure resolve + fileno via an existing libc stream force.
        JitStreamIoKernel::implementFtellForce($context);
        LibcExtern::ensureResolveStreamDecl($context);
        self::ensureFilenoDecl($context);
        self::ensureFstatDecl($context);
        self::ensureHashtableStatDecls($context);

        $probe = $context->module->getNamedFunction('__compiler_fstat');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach (array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
            $fn = $probe;
        } else {
            $i64 = $context->getTypeFromString('int64');
            $htPtr = $context->getTypeFromString('__hashtable__*');
            $fn = $context->module->addFunction(
                '__compiler_fstat',
                $context->context->functionType($htPtr, false, $i64)
            );
        }

        self::emitLibcFstat($context, $fn);
        $context->registerFunction('__compiler_fstat', $fn);

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitLibcFstat(Context $context, LlvmFunction $fn): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $nullPtr = $i8p->constNull();
        $nullHt = $htPtr->constNull();

        $entry = $fn->appendBasicBlock('fstat_libc_entry');
        $fail = $fn->appendBasicBlock('fstat_libc_fail');
        $filenoBb = $fn->appendBasicBlock('fstat_libc_fileno');
        $ok = $fn->appendBasicBlock('fstat_libc_ok');
        $context->builder->positionAtEnd($entry);

        $fp = $context->builder->call(
            $context->lookupFunction('__phpc_resolve_stream'),
            $fn->getParam(0)
        );
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr),
            $fail,
            $filenoBb
        );

        $context->builder->positionAtEnd($filenoBb);
        $fd = $context->builder->call($context->lookupFunction('fileno'), $fp);
        $fdBad = $context->builder->icmp(Builder::INT_SLT, $fd, $i32->constInt(0, true));
        $context->builder->branchIf($fdBad, $fail, $ok);

        $context->builder->positionAtEnd($ok);
        $bufType = $i8->arrayType(self::STAT_BUF_SIZE);
        $buf = $context->builder->alloca($bufType, 1, 'fstat_libc_buf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $rc = $context->builder->call($context->lookupFunction('fstat'), $fd, $bufPtr);
        $statFail = $context->builder->icmp(Builder::INT_NE, $rc, $i32->constInt(0, false));
        $fill = $fn->appendBasicBlock('fstat_libc_fill');
        $context->builder->branchIf($statFail, $fail, $fill);

        $context->builder->positionAtEnd($fill);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        foreach (self::STAT_FIELDS as [$key, $index, $offset, $width]) {
            $bytePtr = $context->builder->gep($bufPtr, $i64->constInt($offset, false));
            if (4 === $width) {
                $fieldPtr = $context->builder->pointerCast($bytePtr, $i32->pointerType(0));
                $loaded = $context->builder->zext($context->builder->load($fieldPtr), $i64);
            } else {
                $fieldPtr = $context->builder->pointerCast($bytePtr, $i64->pointerType(0));
                $loaded = $context->builder->load($fieldPtr);
            }
            $context->builder->call(
                $context->lookupFunction('__hashtable__setLongAt'),
                $ht,
                $i64->constInt($index, false),
                $loaded
            );
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyLong'),
                $ht,
                $context->builder->load($context->constantStringFromString($key)),
                $loaded
            );
        }
        $context->builder->returnValue($ht);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullHt);
    }

    private static function ensureFilenoDecl(Context $context): void
    {
        try {
            $context->lookupFunction('fileno');

            return;
        } catch (\Throwable) {
        }
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $fn = $context->module->getNamedFunction('fileno');
        if (null === $fn) {
            $fn = $context->module->addFunction(
                'fileno',
                $context->context->functionType($i32, false, $i8p)
            );
        }
        $context->registerFunction('fileno', $fn);
    }

    private static function ensureFstatDecl(Context $context): void
    {
        try {
            $context->lookupFunction('fstat');

            return;
        } catch (\Throwable) {
        }
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $fn = $context->module->getNamedFunction('fstat');
        if (null === $fn) {
            $fn = $context->module->addFunction(
                'fstat',
                $context->context->functionType($i32, false, $i32, $i8p)
            );
        }
        $context->registerFunction('fstat', $fn);
    }

    private static function ensureHashtableStatDecls(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->context->voidType();
        foreach (
            [
                ['__hashtable__alloc', $htPtr, []],
                ['__hashtable__setLongAt', $void, [$htPtr, $i64, $i64]],
                ['__hashtable__setStringKeyLong', $void, [$htPtr, $strPtr, $i64]],
            ] as [$name, $ret, $params]
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->getNamedFunction($name);
                if (null === $fn) {
                    $fn = $context->module->addFunction(
                        $name,
                        $context->context->functionType($ret, false, ...$params)
                    );
                }
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function implementFstatBridge(Context $context): void
    {
        $abiName = '__compiler_fstat';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($htPtr, false, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('fstat_bridge_entry');
        $fail = $fn->appendBasicBlock('fstat_bridge_fail');
        $ok = $fn->appendBasicBlock('fstat_bridge_ok');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::FSTAT_HELPER),
            [$fn->getParam(0)]
        );
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($isNull, $fail, $ok);

        $context->builder->positionAtEnd($ok);
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $raw);
        $context->builder->returnValue($ht);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($htPtr->constNull());
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#24586');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24586'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamFstatRuntime bridge (#10460)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
