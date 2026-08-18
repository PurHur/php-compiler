<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Thin standalone AOT gz* stream ABI via linked libz (#30787).
 *
 * NestedJIT {@see GzStreamJitHelper} / {@see VmGzStreamPure} static $streams does not
 * persist across helper calls under thin AOT (returns 0 from gzwrite — peer #26888).
 * php-src: ext/zlib/zlib.c — php_gzopen / php_stream_gzops.
 */
final class JitGzStreamKernel
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_gzopen',
        '__compiler_gzwrite',
        '__compiler_gzread',
        '__compiler_gzgetc',
        '__compiler_gzgets',
        '__compiler_gzclose',
        '__compiler_gzseek',
        '__compiler_gztell',
        '__compiler_gzrewind',
        '__compiler_gzeof',
        '__compiler_gz_read_all',
        '__compiler_gz_passthru',
    ];

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_gzopen');
        if (null !== $probe && $probe->countBasicBlocks() > 0
            && !\PHPCompiler\JIT\Builtin\StreamIoRuntime::isDeferStub($probe)) {
            self::registerLinked($context);

            return;
        }

        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        try {
            self::ensureLibzGz($context);
            self::implementIfMissing($context, '__compiler_gzopen', self::emitGzopen(...));
            self::implementIfMissing($context, '__compiler_gzwrite', self::emitGzwrite(...));
            self::implementIfMissing($context, '__compiler_gzread', self::emitGzread(...));
            self::implementIfMissing($context, '__compiler_gzgetc', self::emitGzgetc(...));
            self::implementIfMissing($context, '__compiler_gzgets', self::emitGzgets(...));
            self::implementIfMissing($context, '__compiler_gzclose', self::emitGzclose(...));
            self::implementIfMissing($context, '__compiler_gzseek', self::emitGzseek(...));
            self::implementIfMissing($context, '__compiler_gztell', self::emitGztell(...));
            self::implementIfMissing($context, '__compiler_gzrewind', self::emitGzrewind(...));
            self::implementIfMissing($context, '__compiler_gzeof', self::emitGzeof(...));
            self::implementIfMissing($context, '__compiler_gz_read_all', self::emitGzReadAll(...));
            self::implementIfMissing($context, '__compiler_gz_passthru', self::emitGzPassthru(...));
            self::registerLinked($context);
        } finally {
            if (null !== $saved) {
                BasicBlockHelper::restoreInsertBlock($context, $saved);
            } else {
                $context->builder->clearInsertionPosition();
            }
        }
    }

    private static function registerLinked(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after JitGzStreamKernel (#30787)');
            }
            $context->registerFunction($name, $fn);
        }
    }

    /** @param callable(Context, LlvmFunction): void $emit */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0
            && !\PHPCompiler\JIT\Builtin\StreamIoRuntime::isDeferStub($probe)) {
            $context->registerFunction($name, $probe);

            return;
        }
        if (null !== $probe && \PHPCompiler\JIT\Builtin\StreamIoRuntime::isDeferStub($probe)) {
            foreach (array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
        }

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = match ($name) {
            '__compiler_gzopen' => $context->context->functionType($i64, false, $strPtr, $strPtr, $i64),
            '__compiler_gzwrite' => $context->context->functionType($i64, false, $i64, $strPtr, $i64),
            '__compiler_gzread', '__compiler_gzgets' => $context->context->functionType($strPtr, false, $i64, $i64),
            '__compiler_gzgetc', '__compiler_gz_read_all' => $context->context->functionType($strPtr, false, $i64),
            '__compiler_gzclose', '__compiler_gzrewind', '__compiler_gzeof' => $context->context->functionType($i32, false, $i64),
            '__compiler_gzseek' => $context->context->functionType($i64, false, $i64, $i64, $i64),
            '__compiler_gztell', '__compiler_gz_passthru' => $context->context->functionType($i64, false, $i64),
            default => throw new \LogicException('JitGzStreamKernel: unknown '.$name),
        };
        $fn = null !== $probe ? $probe : $context->module->addFunction($name, $ft);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function ensureLibzGz(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        // gzFile is an opaque pointer.
        foreach ([
            ['gzopen', $i8p, [$i8p, $i8p]],
            ['gzwrite', $i32, [$i8p, $i8p, $i32]],
            ['gzread', $i32, [$i8p, $i8p, $i32]],
            ['gzclose', $i32, [$i8p]],
            ['gzseek', $i64, [$i8p, $i64, $i32]],
            ['gztell', $i64, [$i8p]],
            ['gzrewind', $i32, [$i8p]],
            ['gzeof', $i32, [$i8p]],
            ['gzgets', $i8p, [$i8p, $i8p, $i32]],
            ['malloc', $i8p, [$i64]],
            ['free', $voidTy, [$i8p]],
            ['strlen', $i64, [$i8p]],
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
        try {
            $context->lookupFunction('__string__init');
        } catch (\Throwable) {
            $strPtr = $context->getTypeFromString('__string__*');
            $fn = $context->module->addFunction(
                '__string__init',
                $context->context->functionType($strPtr, false, $i64, $i8p)
            );
            $context->registerFunction('__string__init', $fn);
        }
        try {
            $context->lookupFunction('__string__strlen');
        } catch (\Throwable) {
            $strPtr = $context->getTypeFromString('__string__*');
            $fn = $context->module->addFunction(
                '__string__strlen',
                $context->context->functionType($i64, false, $strPtr)
            );
            $context->registerFunction('__string__strlen', $fn);
        }
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function emitGzopen(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gzopen_libz_entry');
        $fail = $fn->appendBasicBlock('gzopen_libz_fail');
        $ok = $fn->appendBasicBlock('gzopen_libz_ok');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $path = $fn->getParam(0);
        $mode = $fn->getParam(1);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $mode, $strPtr->constNull())
        );
        $context->builder->branchIf($bad, $fail, $ok);

        $context->builder->positionAtEnd($ok);
        $gz = $context->builder->call(
            $context->lookupFunction('gzopen'),
            self::stringData($context, $path),
            self::stringData($context, $mode)
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $gz, $i8p->constNull());
        $nullBb = $fn->appendBasicBlock('gzopen_libz_null');
        $retBb = $fn->appendBasicBlock('gzopen_libz_ret');
        $context->builder->branchIf($isNull, $nullBb, $retBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->branch($fail);

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnValue($context->builder->ptrToInt($gz, $i64));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));
    }

    private static function emitGzwrite(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gzwrite_libz_entry');
        $fail = $fn->appendBasicBlock('gzwrite_libz_fail');
        $body = $fn->appendBasicBlock('gzwrite_libz_body');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $handle = $fn->getParam(0);
        $data = $fn->getParam(1);
        $length = $fn->getParam(2);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $data, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $handle, $i64->constInt(0, false))
        );
        $context->builder->branchIf($bad, $fail, $body);

        $context->builder->positionAtEnd($body);
        $gz = $context->builder->intToPtr($handle, $i8p);
        $dataLen = $context->builder->call($context->lookupFunction('__string__strlen'), $data);
        $useLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $length, $i64->constInt(0, false)),
            $dataLen,
            $context->builder->select(
                $context->builder->icmp(Builder::INT_SLT, $length, $dataLen),
                $length,
                $dataLen
            )
        );
        $n = $context->builder->call(
            $context->lookupFunction('gzwrite'),
            $gz,
            self::stringData($context, $data),
            $context->builder->trunc($useLen, $i32)
        );
        // gzwrite returns 0 on failure or empty write; negative is also failure.
        $failed = $context->builder->icmp(Builder::INT_SLT, $n, $i32->constInt(0, false));
        $fail2 = $fn->appendBasicBlock('gzwrite_libz_neg');
        $ok = $fn->appendBasicBlock('gzwrite_libz_ok');
        $context->builder->branchIf($failed, $fail2, $ok);

        $context->builder->positionAtEnd($fail2);
        $context->builder->branch($fail);

        $context->builder->positionAtEnd($ok);
        $context->builder->returnValue($context->builder->zExt($n, $i64));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));
    }

    private static function emitGzread(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gzread_libz_entry');
        $fail = $fn->appendBasicBlock('gzread_libz_fail');
        $body = $fn->appendBasicBlock('gzread_libz_body');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $handle = $fn->getParam(0);
        $length = $fn->getParam(1);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $handle, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SLE, $length, $i64->constInt(0, false))
        );
        $context->builder->branchIf($bad, $fail, $body);

        $context->builder->positionAtEnd($body);
        $buf = $context->builder->call($context->lookupFunction('malloc'), $length);
        $allocFail = $context->builder->icmp(Builder::INT_EQ, $buf, $i8p->constNull());
        $doRead = $fn->appendBasicBlock('gzread_libz_do');
        $context->builder->branchIf($allocFail, $fail, $doRead);

        $context->builder->positionAtEnd($doRead);
        $n = $context->builder->call(
            $context->lookupFunction('gzread'),
            $context->builder->intToPtr($handle, $i8p),
            $buf,
            $context->builder->trunc($length, $i32)
        );
        $neg = $context->builder->icmp(Builder::INT_SLT, $n, $i32->constInt(0, false));
        $freeFail = $fn->appendBasicBlock('gzread_libz_free_fail');
        $ok = $fn->appendBasicBlock('gzread_libz_ok');
        $context->builder->branchIf($neg, $freeFail, $ok);

        $context->builder->positionAtEnd($freeFail);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->branch($fail);

        $context->builder->positionAtEnd($ok);
        $n64 = $context->builder->zExt($n, $i64);
        $str = $context->builder->call($context->lookupFunction('__string__init'), $n64, $buf);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue($str);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($strPtr->constNull());
    }

    private static function emitGzgetc(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gzgetc_libz_entry');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $result = $context->builder->call(
            $context->lookupFunction('__compiler_gzread'),
            $fn->getParam(0),
            $i64->constInt(1, false)
        );
        $context->builder->returnValue($result);
    }

    private static function emitGzgets(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gzgets_libz_entry');
        $fail = $fn->appendBasicBlock('gzgets_libz_fail');
        $body = $fn->appendBasicBlock('gzgets_libz_body');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $handle = $fn->getParam(0);
        $length = $fn->getParam(1);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $handle, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SLE, $length, $i64->constInt(1, false))
        );
        $context->builder->branchIf($bad, $fail, $body);

        $context->builder->positionAtEnd($body);
        $buf = $context->builder->call($context->lookupFunction('malloc'), $length);
        $allocFail = $context->builder->icmp(Builder::INT_EQ, $buf, $i8p->constNull());
        $doRead = $fn->appendBasicBlock('gzgets_libz_do');
        $context->builder->branchIf($allocFail, $fail, $doRead);

        $context->builder->positionAtEnd($doRead);
        $got = $context->builder->call(
            $context->lookupFunction('gzgets'),
            $context->builder->intToPtr($handle, $i8p),
            $buf,
            $context->builder->trunc($length, $i32)
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $got, $i8p->constNull());
        $freeFail = $fn->appendBasicBlock('gzgets_libz_free_fail');
        $ok = $fn->appendBasicBlock('gzgets_libz_ok');
        $context->builder->branchIf($isNull, $freeFail, $ok);

        $context->builder->positionAtEnd($freeFail);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->branch($fail);

        $context->builder->positionAtEnd($ok);
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $len = $context->builder->call($context->lookupFunction('strlen'), $buf);
        $str = $context->builder->call($context->lookupFunction('__string__init'), $len, $buf);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue($str);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($strPtr->constNull());
    }

    private static function emitGzclose(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gzclose_libz_entry');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $handle = $fn->getParam(0);
        $bad = $context->builder->icmp(Builder::INT_EQ, $handle, $i64->constInt(0, false));
        $fail = $fn->appendBasicBlock('gzclose_libz_fail');
        $ok = $fn->appendBasicBlock('gzclose_libz_ok');
        $context->builder->branchIf($bad, $fail, $ok);

        $context->builder->positionAtEnd($ok);
        $rc = $context->builder->call(
            $context->lookupFunction('gzclose'),
            $context->builder->intToPtr($handle, $i8p)
        );
        $okRc = $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(0, false));
        $context->builder->returnValue(
            $context->builder->select($okRc, $i32->constInt(1, false), $i32->constInt(0, false))
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i32->constInt(0, false));
    }

    private static function emitGzseek(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gzseek_libz_entry');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $rc = $context->builder->call(
            $context->lookupFunction('gzseek'),
            $context->builder->intToPtr($fn->getParam(0), $i8p),
            $fn->getParam(1),
            $context->builder->trunc($fn->getParam(2), $i32)
        );
        $context->builder->returnValue($rc);
    }

    private static function emitGztell(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gztell_libz_entry');
        $context->builder->positionAtEnd($entry);
        $i8p = $context->getTypeFromString('int8*');
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('gztell'),
                $context->builder->intToPtr($fn->getParam(0), $i8p)
            )
        );
    }

    private static function emitGzrewind(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gzrewind_libz_entry');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $rc = $context->builder->call(
            $context->lookupFunction('gzrewind'),
            $context->builder->intToPtr($fn->getParam(0), $i8p)
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(0, false));
        $context->builder->returnValue(
            $context->builder->select($ok, $i32->constInt(1, false), $i32->constInt(0, false))
        );
    }

    private static function emitGzeof(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gzeof_libz_entry');
        $context->builder->positionAtEnd($entry);
        $i8p = $context->getTypeFromString('int8*');
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('gzeof'),
                $context->builder->intToPtr($fn->getParam(0), $i8p)
            )
        );
    }

    private static function emitGzReadAll(Context $context, LlvmFunction $fn): void
    {
        // Chunked read via __compiler_gzread — keep simple for thin AOT.
        $entry = $fn->appendBasicBlock('gz_read_all_libz_entry');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        // Single large read — good enough for typical files in thin AOT.
        $chunk = $context->builder->call(
            $context->lookupFunction('__compiler_gzread'),
            $fn->getParam(0),
            $i64->constInt(1 << 20, false)
        );
        $context->builder->returnValue($chunk);
    }

    private static function emitGzPassthru(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gz_passthru_libz_entry');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        // Unsupported as full echo passthru under thin kernel — return -1.
        $context->builder->returnValue($i64->constInt(-1, true));
    }
}
