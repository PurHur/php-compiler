<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_ftok — thin AOT emits libc stat(2) + VmFtokPure layout (#9585, #27389).
 *
 * NestedJIT of FtokJitHelper→VmFtok is an unbound ExternalMethod stub under thin AOT
 * (returns 0). Peer {@see \PHPCompiler\ext\standard\JitStatKernel} / StringMicrotime (#26930):
 * emit the platform leaf in LLVM; keep {@see \PHPCompiler\ext\standard\VmFtokPure} as VM SSOT.
 *
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT: "Current basic block has no parent function", #27389 / peer #27088).
 * php-src: ext/standard/ftok.c — PHP_FUNCTION(ftok) (libc ftok(3) / System V key layout)
 */
final class FtokRuntime
{
    /** sizeof(struct stat) on Linux x86_64 glibc — peer {@see \PHPCompiler\ext\standard\JitStatKernel}. */
    private const STAT_BUF_SIZE = 144;

    /** offsetof(struct stat, st_dev) */
    private const STAT_DEV_OFFSET = 0;

    /** offsetof(struct stat, st_ino) */
    private const STAT_INO_OFFSET = 8;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_ftok',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_ftok');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Preserve caller insert block — clearInsertionPosition alone orphans mid-emit (#27389 / #27088).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureLibcStat($context);
        self::implementIfMissing($context, '__compiler_ftok', self::implementFtokBridge(...));
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
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        return $context->module->addFunction(
            $name,
            $context->context->functionType($i64, false, $strPtr, $i32)
        );
    }

    /**
     * System V IPC key: (ino & 0xffff) | ((dev & 0xff) << 16) | ((proj & 0xff) << 24).
     * Same formula as {@see \PHPCompiler\ext\standard\VmFtokPure::invoke}.
     */
    private static function implementFtokBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ftok_bridge_entry');
        $fail = $fn->appendBasicBlock('ftok_bridge_fail');
        $body = $fn->appendBasicBlock('ftok_bridge_body');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $path = $fn->getParam(0);
        $projId = $fn->getParam(1);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($body);
        $map = $context->structFieldMap['__string__'];
        $pathC = $context->builder->structGep($path, $map['value']);
        $bufType = $i8->arrayType(self::STAT_BUF_SIZE);
        $buf = $context->builder->alloca($bufType, 1, 'ftok_stat_buf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $statRet = $context->builder->call($context->lookupFunction('stat'), $pathC, $bufPtr);
        $statFailed = $context->builder->icmp(Builder::INT_NE, $statRet, $i32->constInt(0, false));
        $ok = $fn->appendBasicBlock('ftok_bridge_ok');
        $context->builder->branchIf($statFailed, $fail, $ok);

        $context->builder->positionAtEnd($ok);
        $devPtr = $context->builder->pointerCast(
            $context->builder->gep($bufPtr, $i64->constInt(self::STAT_DEV_OFFSET, false)),
            $i64->pointerType(0)
        );
        $inoPtr = $context->builder->pointerCast(
            $context->builder->gep($bufPtr, $i64->constInt(self::STAT_INO_OFFSET, false)),
            $i64->pointerType(0)
        );
        $dev = $context->builder->load($devPtr);
        $ino = $context->builder->load($inoPtr);
        $proj = $context->builder->zExt($projId, $i64);
        $inoPart = $context->builder->and($ino, $i64->constInt(0xFFFF, false));
        $devPart = $context->builder->shl(
            $context->builder->and($dev, $i64->constInt(0xFF, false)),
            $i64->constInt(16, false)
        );
        $projPart = $context->builder->shl(
            $context->builder->and($proj, $i64->constInt(0xFF, false)),
            $i64->constInt(24, false)
        );
        $key = $context->builder->or($context->builder->or($inoPart, $devPart), $projPart);
        $context->builder->returnValue($key);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));
    }

    private static function ensureLibcStat(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        try {
            $context->lookupFunction('stat');
        } catch (\Throwable) {
            $fn = $context->module->addFunction(
                'stat',
                $context->context->functionType($i32, false, $i8p, $i8p)
            );
            $context->registerFunction('stat', $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after FtokRuntime bridge (#9585)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
