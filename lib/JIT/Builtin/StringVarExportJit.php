<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM __compiler_var_export (mirrors ext/standard/var_export.php / former phpc_var_export.c).
 *
 * php-src: ext/standard/var.c — php_var_export_ex()
 * Issue #5190.
 */
final class StringVarExportJit
{
    private const BUF_OFF_PTR = 0;

    private const BUF_OFF_LEN = 8;

    private const BUF_OFF_CAP = 16;

    private const BUF_SIZE = 24;

    private const CIRCULAR_WARNING = 'var_export does not handle circular references';

    private const MAX_CYCLE_STACK = 64;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        $probe = $context->module->getNamedFunction('__compiler_var_export');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_var_export', $probe);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::ensureLibc($context);
        self::ensureValueHelpers($context);

        foreach ([
            '__phpc_ve_export_array',
            '__phpc_ve_export_value',
            '__compiler_var_export',
        ] as $recursiveName) {
            $probe = $context->module->getNamedFunction($recursiveName);
            if (null === $probe || 0 === $probe->countBasicBlocks()) {
                $fn = self::declareFunction($context, $recursiveName);
                $context->registerFunction($recursiveName, $fn);
            }
        }

        foreach ([
            '__phpc_ve_buf_append_bytes' => self::emitBufAppendBytes(...),
            '__phpc_ve_buf_append_cstr' => self::emitBufAppendCstr(...),
            '__phpc_ve_buf_append_char' => self::emitBufAppendChar(...),
            '__phpc_ve_buf_append_indent' => self::emitBufAppendIndent(...),
            '__phpc_ve_buf_append_ll' => self::emitBufAppendLl(...),
            '__phpc_ve_buf_append_double' => self::emitBufAppendDouble(...),
            '__phpc_ve_buf_append_quoted_string' => self::emitBufAppendQuotedString(...),
            '__phpc_ve_export_array' => self::emitExportArray(...),
            '__phpc_ve_export_value' => self::emitExportValue(...),
            '__compiler_var_export' => self::emitCompilerVarExport(...),
        ] as $name => $emit) {
            self::implementIfMissing($context, $name, $emit);
        }

        self::restoreInsertBlock($context, $restore);
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
        $context->registerFunction($name, $fn);
        $emit($context, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $existing = $context->module->getNamedFunction($name);
        if (null !== $existing) {
            return $existing;
        }

        $void = $context->getTypeFromString('void');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valuePtr = $context->getTypeFromString('__value__*');

        return match ($name) {
            '__phpc_ve_buf_append_bytes' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $voidPtr, $i8p, $sizeT)
            ),
            '__phpc_ve_buf_append_cstr' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $voidPtr, $i8p)
            ),
            '__phpc_ve_buf_append_char' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $voidPtr, $i8)
            ),
            '__phpc_ve_buf_append_indent' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $voidPtr, $i32)
            ),
            '__phpc_ve_buf_append_ll' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $voidPtr, $i64)
            ),
            '__phpc_ve_buf_append_double' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $voidPtr, $dbl)
            ),
            '__phpc_ve_buf_append_quoted_string' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $voidPtr, $strPtr)
            ),
            '__phpc_ve_export_array' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $voidPtr, $htPtr, $i32, $htPtr->pointerType(0), $i32)
            ),
            '__phpc_ve_export_value' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $voidPtr, $valuePtr, $i32, $htPtr->pointerType(0), $i32)
            ),
            '__compiler_var_export' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $valuePtr)
            ),
            default => throw new \LogicException('Unknown var_export JIT helper: '.$name),
        };
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $dbl = $context->getTypeFromString('double');

        foreach ([
            ['malloc', $voidPtr, [$sizeT]],
            ['free', $voidTy, [$i8p]],
            ['realloc', $voidPtr, [$voidPtr, $sizeT]],
            ['memcpy', $voidPtr, [$voidPtr, $voidPtr, $sizeT]],
            ['strlen', $sizeT, [$i8p]],
            ['snprintf', $i32, [$charPtr, $sizeT, $charPtr], true],
            ['isnan', $i32, [$dbl]],
            ['isinf', $i32, [$dbl]],
            ['signbit', $i32, [$dbl]],
        ] as $spec) {
            $name = $spec[0];
            $ret = $spec[1];
            $params = $spec[2];
            $vararg = $spec[3] ?? false;
            self::ensureExternal($context, $name, $context->context->functionType($ret, $vararg, ...$params));
        }
    }

    private static function ensureValueHelpers(Context $context): void
    {
        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $voidTy = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');

        foreach ([
            ['__string__init', $strPtr, [$i64, $context->getTypeFromString('int8*')]],
            ['__value__readLong', $i64, [$valuePtr]],
            ['__value__readDouble', $dbl, [$valuePtr]],
            ['__value__readString', $strPtr, [$valuePtr]],
            ['__value__readHashtable', $htPtr, [$valuePtr]],
            ['__hashtable__offsetIsSet', $i32, [$htPtr, $sizeT]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $fnType): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $fnType);
            $context->registerFunction($name, $fn);
        }
    }

    private static function emitCompilerVarExport(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ve_entry');
        $context->builder->positionAtEnd($entry);

        $voidPtr = $context->getTypeFromString('void*');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtrTy = $context->getTypeFromString('__value__*');

        $v = $fn->getParam(0);
        $buf = $context->builder->alloca($context->getTypeFromString('int8'), self::BUF_SIZE, 've_buf');
        $bufVoid = $context->bytePtr($buf);

        $initCap = $sizeT->constInt(256, false);
        $bufPtr = $context->builder->call($context->lookupFunction('malloc'), $initCap);
        $bufCast = $context->builder->pointerCast($bufPtr, $i8p);
        $context->builder->store($bufCast, $context->builder->pointerCast(
            $context->builder->gep($buf, $i32->constInt(self::BUF_OFF_PTR, false)),
            $i8p->pointerType(0)
        ));
        $context->builder->store($sizeT->constInt(0, false), $context->builder->pointerCast(
            $context->builder->gep($buf, $i32->constInt(self::BUF_OFF_LEN, false)),
            $sizeT->pointerType(0)
        ));
        $context->builder->store($initCap, $context->builder->pointerCast(
            $context->builder->gep($buf, $i32->constInt(self::BUF_OFF_CAP, false)),
            $sizeT->pointerType(0)
        ));

        $nullV = $context->builder->icmp(Builder::INT_EQ, $v, $valuePtrTy->constNull());
        $nullBb = $fn->appendBasicBlock('ve_null_v');
        $bodyBb = $fn->appendBasicBlock('ve_body');
        $context->builder->branchIf($nullV, $nullBb, $bodyBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_cstr'),
            $bufVoid,
            $context->builder->pointerCast($context->constantFromString('NULL'), $i8p)
        );
        $context->builder->branch($bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $stack = $context->builder->alloca($htPtrTy->pointerType(0), self::MAX_CYCLE_STACK, 've_cycle_stack');
        $depthZero = $i32->constInt(0, false);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_export_value'),
            $bufVoid,
            $v,
            $i32->constInt(0, false),
            $stack,
            $depthZero
        );

        $len = $context->builder->load($context->builder->pointerCast(
            $context->builder->gep($buf, $i32->constInt(self::BUF_OFF_LEN, false)),
            $sizeT->pointerType(0)
        ));
        $data = $context->builder->load($context->builder->pointerCast(
            $context->builder->gep($buf, $i32->constInt(self::BUF_OFF_PTR, false)),
            $i8p->pointerType(0)
        ));
        $out = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $data
        );
        $context->builder->call($context->lookupFunction('free'), $data);
        $context->builder->returnValue($out);
    }

    private static function emitBufAppendBytes(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $voidPtr = $context->getTypeFromString('void*');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $buf = $fn->getParam(0);
        $bytes = $fn->getParam(1);
        $n = $fn->getParam(2);

        $zeroN = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $nullBytes = $context->builder->icmp(Builder::INT_EQ, $bytes, $i8p->constNull());
        $skipBb = $fn->appendBasicBlock('skip');
        $bodyBb = $fn->appendBasicBlock('body');
        $context->builder->branchIf(
            $context->builder->or($zeroN, $nullBytes),
            $skipBb,
            $bodyBb
        );

        $context->builder->positionAtEnd($bodyBb);
        $lenPtr = self::bufFieldPtr($context, $buf, self::BUF_OFF_LEN, $sizeT);
        $capPtr = self::bufFieldPtr($context, $buf, self::BUF_OFF_CAP, $sizeT);
        $ptrPtr = self::bufFieldPtr($context, $buf, self::BUF_OFF_PTR, $i8p);
        $newCapSlot = $context->builder->alloca($sizeT, 1, 've_new_cap');
        $context->builder->store($context->builder->load($capPtr), $newCapSlot);

        $growHead = $fn->appendBasicBlock('grow_head');
        $growBody = $fn->appendBasicBlock('grow_body');
        $appendBb = $fn->appendBasicBlock('append');
        $context->builder->branch($growHead);

        $context->builder->positionAtEnd($growHead);
        $len = $context->builder->load($lenPtr);
        $need = $context->builder->add($context->builder->add($len, $n), $one);
        $tryCap = $context->builder->load($newCapSlot);
        $fits = $context->builder->icmp(Builder::INT_UGE, $tryCap, $need);
        $context->builder->branchIf($fits, $appendBb, $growBody);

        $context->builder->positionAtEnd($growBody);
        $doubled = $context->builder->mul($tryCap, $sizeT->constInt(2, false));
        $context->builder->store($doubled, $newCapSlot);
        $context->builder->branch($growHead);

        $context->builder->positionAtEnd($appendBb);
        $len = $context->builder->load($lenPtr);
        $oldPtr = $context->builder->load($ptrPtr);
        $newCap = $context->builder->load($newCapSlot);
        $grown = $context->builder->call($context->lookupFunction('realloc'), $oldPtr, $newCap);
        $grownCast = $context->builder->pointerCast($grown, $i8p);
        $context->builder->store($grownCast, $ptrPtr);
        $context->builder->store($newCap, $capPtr);
        $data = $context->builder->load($ptrPtr);
        $dest = $context->builder->gep($data, $len);
        $context->builder->call($context->lookupFunction('memcpy'), $dest, $bytes, $n);
        $newLen = $context->builder->add($len, $n);
        $context->builder->store($newLen, $lenPtr);
        $term = $context->builder->gep($data, $newLen);
        $context->builder->store($context->getTypeFromString('int8')->constInt(0, false), $term);
        $doneBb = $fn->appendBasicBlock('done');
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function emitBufAppendCstr(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $buf = $fn->getParam(0);
        $cstr = $fn->getParam(1);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $nullC = $context->builder->icmp(Builder::INT_EQ, $cstr, $i8p->constNull());
        $done = $fn->appendBasicBlock('done');
        $body = $fn->appendBasicBlock('body');
        $context->builder->branchIf($nullC, $done, $body);
        $context->builder->positionAtEnd($body);
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_bytes'),
            $buf,
            $cstr,
            $len
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
    }

    private static function emitBufAppendChar(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $chSlot = $context->builder->alloca($context->getTypeFromString('int8'), 1);
        $context->builder->store($fn->getParam(1), $chSlot);
        $ptr = $context->builder->pointerCast($chSlot, $context->getTypeFromString('int8*'));
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_bytes'),
            $fn->getParam(0),
            $ptr,
            $context->getTypeFromString('size_t')->constInt(1, false)
        );
        $context->builder->returnVoid();
    }

    private static function emitBufAppendIndent(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $buf = $fn->getParam(0);
        $level = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $idxSlot = $context->builder->alloca($i32, 1, 've_indent_i');
        $context->builder->store($i32->constInt(0, false), $idxSlot);
        $head = $fn->appendBasicBlock('indent_head');
        $body = $fn->appendBasicBlock('indent_body');
        $done = $fn->appendBasicBlock('indent_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $level);
        $context->builder->branchIf($atEnd, $done, $body);
        $context->builder->positionAtEnd($body);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_bytes'),
            $buf,
            $context->builder->pointerCast($context->constantFromString('  '), $context->getTypeFromString('int8*')),
            $context->getTypeFromString('size_t')->constInt(2, false)
        );
        $context->builder->store($context->builder->add($i, $i32->constInt(1, false)), $idxSlot);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
    }

    private static function emitBufAppendLl(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $tmp = $context->builder->alloca($context->getTypeFromString('int8'), 64, 've_ll_tmp');
        $tmpPtr = $context->builder->pointerCast($tmp, $context->getTypeFromString('char*'));
        $n = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $tmpPtr,
            $context->getTypeFromString('size_t')->constInt(64, false),
            $context->builder->pointerCast($context->constantFromString('%lld'), $context->getTypeFromString('char*')),
            $fn->getParam(1)
        );
        $pos = $context->builder->zExt($n, $context->getTypeFromString('size_t'));
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_bytes'),
            $fn->getParam(0),
            $context->builder->pointerCast($tmp, $context->getTypeFromString('int8*')),
            $pos
        );
        $context->builder->returnVoid();
    }

    private static function emitBufAppendDouble(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $buf = $fn->getParam(0);
        $num = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $dbl = $context->getTypeFromString('double');
        $zeroI32 = $i32->constInt(0, false);
        $appendCstr = $context->lookupFunction('__phpc_ve_buf_append_cstr');

        $isNan = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('isnan'), $num),
            $zeroI32
        );
        $nanBb = $fn->appendBasicBlock('ve_dbl_nan');
        $checkInf = $fn->appendBasicBlock('ve_dbl_check_inf');
        $context->builder->branchIf($isNan, $nanBb, $checkInf);

        $context->builder->positionAtEnd($nanBb);
        $nanNeg = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('signbit'), $num),
            $zeroI32
        );
        $nanNegBb = $fn->appendBasicBlock('ve_dbl_nan_neg');
        $nanPosBb = $fn->appendBasicBlock('ve_dbl_nan_pos');
        $nanDone = $fn->appendBasicBlock('ve_dbl_nan_done');
        $context->builder->branchIf($nanNeg, $nanNegBb, $nanPosBb);
        $context->builder->positionAtEnd($nanNegBb);
        $context->builder->call(
            $appendCstr,
            $buf,
            $context->builder->pointerCast($context->constantFromString('-NAN'), $i8p)
        );
        $context->builder->branch($nanDone);
        $context->builder->positionAtEnd($nanPosBb);
        $context->builder->call(
            $appendCstr,
            $buf,
            $context->builder->pointerCast($context->constantFromString('NAN'), $i8p)
        );
        $context->builder->branch($nanDone);
        $context->builder->positionAtEnd($nanDone);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($checkInf);
        $isInf = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('isinf'), $num),
            $zeroI32
        );
        $infBb = $fn->appendBasicBlock('ve_dbl_inf');
        $finiteBb = $fn->appendBasicBlock('ve_dbl_finite');
        $context->builder->branchIf($isInf, $infBb, $finiteBb);

        $context->builder->positionAtEnd($infBb);
        $negInf = $context->builder->fcmp(Builder::REAL_OLT, $num, $dbl->constReal(0.0));
        $infNegBb = $fn->appendBasicBlock('ve_dbl_inf_neg');
        $infPosBb = $fn->appendBasicBlock('ve_dbl_inf_pos');
        $infDone = $fn->appendBasicBlock('ve_dbl_inf_done');
        $context->builder->branchIf($negInf, $infNegBb, $infPosBb);
        $context->builder->positionAtEnd($infNegBb);
        $context->builder->call(
            $appendCstr,
            $buf,
            $context->builder->pointerCast($context->constantFromString('-INF'), $i8p)
        );
        $context->builder->branch($infDone);
        $context->builder->positionAtEnd($infPosBb);
        $context->builder->call(
            $appendCstr,
            $buf,
            $context->builder->pointerCast($context->constantFromString('INF'), $i8p)
        );
        $context->builder->branch($infDone);
        $context->builder->positionAtEnd($infDone);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($finiteBb);
        $tmp = $context->builder->alloca($context->getTypeFromString('int8'), 128, 've_dbl_tmp');
        $tmpPtr = $context->builder->pointerCast($tmp, $charPtr);
        $n = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $tmpPtr,
            $sizeT->constInt(128, false),
            $context->builder->pointerCast($context->constantFromString('%G'), $charPtr),
            $num
        );
        $pos = $context->builder->zExt($n, $sizeT);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_bytes'),
            $buf,
            $context->builder->pointerCast($tmp, $i8p),
            $pos
        );
        $context->builder->returnVoid();
    }

    private static function emitBufAppendQuotedString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $buf = $fn->getParam(0);
        $str = $fn->getParam(1);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $map = self::stringFieldMap($context);

        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_char'),
            $buf,
            $i8->constInt(39, false)
        );

        $nullStr = $context->builder->icmp(Builder::INT_EQ, $str, $strPtrTy->constNull());
        $emptyBb = $fn->appendBasicBlock('qs_empty');
        $loopHead = $fn->appendBasicBlock('qs_head');
        $done = $fn->appendBasicBlock('qs_done');
        $context->builder->branchIf($nullStr, $emptyBb, $loopHead);

        $idxSlot = $context->builder->alloca($sizeT, 1, 've_qs_i');
        $len = $context->builder->load($context->builder->structGep($str, $map['length']));
        $data = $context->builder->structGep($str, $map['value']);
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $loopBody = $fn->appendBasicBlock('qs_body');
        $context->builder->branchIf($atEnd, $emptyBb, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->gep($data, $i));
        $isSlash = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(92, false));
        $isQuote = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(39, false));
        $escape = $context->builder->or($isSlash, $isQuote);
        $noEscBb = $fn->appendBasicBlock('qs_no_esc');
        $escBb = $fn->appendBasicBlock('qs_esc');
        $nextBb = $fn->appendBasicBlock('qs_next');
        $context->builder->branchIf($escape, $escBb, $noEscBb);

        $context->builder->positionAtEnd($escBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_char'),
            $buf,
            $i8->constInt(92, false)
        );
        $context->builder->branch($noEscBb);

        $context->builder->positionAtEnd($noEscBb);
        $context->builder->call($context->lookupFunction('__phpc_ve_buf_append_char'), $buf, $ch);
        $context->builder->branch($nextBb);

        $context->builder->positionAtEnd($nextBb);
        $context->builder->store($context->builder->add($i, $sizeT->constInt(1, false)), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_char'),
            $buf,
            $i8->constInt(39, false)
        );
        $context->builder->returnVoid();
    }

    private static function emitExportArray(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $buf = $fn->getParam(0);
        $ht = $fn->getParam(1);
        $level = $fn->getParam(2);
        $stack = $fn->getParam(3);
        $depth = $fn->getParam(4);
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');

        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_cstr'),
            $buf,
            $context->builder->pointerCast($context->constantFromString("array (\n"), $context->getTypeFromString('int8*'))
        );

        $nullHt = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtrTy->constNull());
        $strInit = $fn->appendBasicBlock('ea_str_init');
        $done = $fn->appendBasicBlock('ea_done');
        $packedInit = $fn->appendBasicBlock('ea_packed_init');
        $context->builder->branchIf($nullHt, $strInit, $packedInit);

        $context->builder->positionAtEnd($packedInit);
        $idxSlot = $context->builder->alloca($sizeT, 1, 've_ea_i');
        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);
        $packedHead = $fn->appendBasicBlock('ea_packed_head');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $packedBody = $fn->appendBasicBlock('ea_packed_body');
        $context->builder->branchIf($atEnd, $strInit, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $values = $context->builder->load($context->builder->structGep($ht, $map['values']));
        $entryPtr = $context->builder->inBoundsGep($values, $idx);
        $kind = self::loadValueKind($context, $entryPtr);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $context->getTypeFromString('int8')->constInt(JITVariable::TYPE_NULL, false)
        );
        $emitBb = $fn->appendBasicBlock('ea_emit_packed');
        $nextBb = $fn->appendBasicBlock('ea_packed_next');
        $context->builder->branchIf($isNull, $nextBb, $emitBb);

        $context->builder->positionAtEnd($emitBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_indent'),
            $buf,
            $context->builder->add($level, $i32->constInt(1, false))
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_ll'),
            $buf,
            $context->builder->zExt($idx, $i64)
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_cstr'),
            $buf,
            $context->builder->pointerCast($context->constantFromString(' => '), $context->getTypeFromString('int8*'))
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_export_value'),
            $buf,
            $entryPtr,
            $context->builder->add($level, $i32->constInt(1, false)),
            $stack,
            $depth
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_cstr'),
            $buf,
            $context->builder->pointerCast($context->constantFromString(",\n"), $context->getTypeFromString('int8*'))
        );
        $context->builder->branch($nextBb);

        $context->builder->positionAtEnd($nextBb);
        $context->builder->store($context->builder->add($idx, $sizeT->constInt(1, false)), $idxSlot);
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrTy, 1, 've_ea_walk');
        $loadHeadBb = $fn->appendBasicBlock('ea_load_head');
        $nullHtBb = $fn->appendBasicBlock('ea_null_ht_str');
        $context->builder->branchIf($nullHt, $nullHtBb, $loadHeadBb);
        $context->builder->positionAtEnd($loadHeadBb);
        $headNode = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $storeHeadBb = $fn->appendBasicBlock('ea_store_head');
        $context->builder->branch($storeHeadBb);
        $context->builder->positionAtEnd($nullHtBb);
        $headNode = $nodePtrTy->constNull();
        $context->builder->branch($storeHeadBb);
        $context->builder->positionAtEnd($storeHeadBb);
        $context->builder->store($headNode, $walkSlot);
        $strHead = $fn->appendBasicBlock('ea_str_head');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $strBody = $fn->appendBasicBlock('ea_str_body');
        $context->builder->branchIf($nodeNull, $done, $strBody);

        $context->builder->positionAtEnd($strBody);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_indent'),
            $buf,
            $context->builder->add($level, $i32->constInt(1, false))
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_quoted_string'),
            $buf,
            $keyStr
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_cstr'),
            $buf,
            $context->builder->pointerCast($context->constantFromString(' => '), $context->getTypeFromString('int8*'))
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_export_value'),
            $buf,
            $valEntry,
            $context->builder->add($level, $i32->constInt(1, false)),
            $stack,
            $depth
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_cstr'),
            $buf,
            $context->builder->pointerCast($context->constantFromString(",\n"), $context->getTypeFromString('int8*'))
        );
        $strNext = $fn->appendBasicBlock('ea_str_next');
        $context->builder->branch($strNext);
        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($done);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_indent'),
            $buf,
            $level
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_char'),
            $buf,
            $context->getTypeFromString('int8')->constInt(41, false)
        );
        $context->builder->returnVoid();
    }

    private static function emitExportValue(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $buf = $fn->getParam(0);
        $v = $fn->getParam(1);
        $level = $fn->getParam(2);
        $stack = $fn->getParam(3);
        $depth = $fn->getParam(4);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');

        $nullV = $context->builder->icmp(Builder::INT_EQ, $v, $valuePtrTy->constNull());
        $nullBb = $fn->appendBasicBlock('ev_null');
        $switchBb = $fn->appendBasicBlock('ev_switch');
        $context->builder->branchIf($nullV, $nullBb, $switchBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_cstr'),
            $buf,
            $context->builder->pointerCast($context->constantFromString('NULL'), $context->getTypeFromString('int8*'))
        );
        $done = $fn->appendBasicBlock('ev_done');
        $context->builder->branch($done);

        $context->builder->positionAtEnd($switchBb);
        $kind = self::loadValueKind($context, $v);
        $defaultBb = $fn->appendBasicBlock('ev_default');

        $cases = [
            JITVariable::TYPE_NULL => 'ev_null_kind',
            JITVariable::TYPE_NATIVE_BOOL => 'ev_bool',
            JITVariable::TYPE_NATIVE_LONG => 'ev_long',
            JITVariable::TYPE_NATIVE_DOUBLE => 'ev_double',
            JITVariable::TYPE_STRING => 'ev_string',
            JITVariable::TYPE_HASHTABLE => 'ev_array',
        ];
        $blocks = [];
        foreach ($cases as $typeConst => $label) {
            $blocks[$typeConst] = $fn->appendBasicBlock($label);
        }

        $switch = $context->builder->branchSwitch($kind, $defaultBb, count($cases));
        foreach ($cases as $typeConst => $label) {
            $switch->addCase($i8->constInt($typeConst, false), $blocks[$typeConst]);
        }

        $context->builder->positionAtEnd($blocks[JITVariable::TYPE_NULL]);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_cstr'),
            $buf,
            $context->builder->pointerCast($context->constantFromString('NULL'), $context->getTypeFromString('int8*'))
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($blocks[JITVariable::TYPE_NATIVE_BOOL]);
        $boolVal = $context->builder->call($context->lookupFunction('__value__readLong'), $v);
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $boolVal,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $trueBb = $fn->appendBasicBlock('ev_true');
        $falseBb = $fn->appendBasicBlock('ev_false');
        $boolDone = $fn->appendBasicBlock('ev_bool_done');
        $context->builder->branchIf($isTrue, $trueBb, $falseBb);
        $context->builder->positionAtEnd($trueBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_cstr'),
            $buf,
            $context->builder->pointerCast($context->constantFromString('true'), $context->getTypeFromString('int8*'))
        );
        $context->builder->branch($boolDone);
        $context->builder->positionAtEnd($falseBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_cstr'),
            $buf,
            $context->builder->pointerCast($context->constantFromString('false'), $context->getTypeFromString('int8*'))
        );
        $context->builder->branch($boolDone);
        $context->builder->positionAtEnd($boolDone);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($blocks[JITVariable::TYPE_NATIVE_LONG]);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_ll'),
            $buf,
            $context->builder->call($context->lookupFunction('__value__readLong'), $v)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($blocks[JITVariable::TYPE_NATIVE_DOUBLE]);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_double'),
            $buf,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $v)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($blocks[JITVariable::TYPE_STRING]);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_quoted_string'),
            $buf,
            $context->builder->call($context->lookupFunction('__value__readString'), $v)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($blocks[JITVariable::TYPE_HASHTABLE]);
        $htPtr = $context->builder->call($context->lookupFunction('__value__readHashtable'), $v);
        $isStreamCtx = \PHPCompiler\ext\standard\JitStreamContextRepresentation::isRepresentation($context, $htPtr);
        $streamCtxBb = $fn->appendBasicBlock('ev_stream_context');
        $arrayExportBb = $fn->appendBasicBlock('ev_array_export');
        $context->builder->branchIf($isStreamCtx, $streamCtxBb, $arrayExportBb);
        $context->builder->positionAtEnd($streamCtxBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_cstr'),
            $buf,
            $context->builder->pointerCast($context->constantFromString('NULL'), $context->getTypeFromString('int8*'))
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($arrayExportBb);
        $cycleBb = $fn->appendBasicBlock('ev_array_cycle');
        $pushBb = $fn->appendBasicBlock('ev_array_push');
        self::branchIfArrayCycle($context, $fn, $htPtr, $stack, $depth, $cycleBb, $pushBb);
        $context->builder->positionAtEnd($cycleBb);
        self::emitCircularWarning($context);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_cstr'),
            $buf,
            $context->builder->pointerCast($context->constantFromString('NULL'), $context->getTypeFromString('int8*'))
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($pushBb);
        $context->builder->store(
            $htPtr,
            $context->builder->inBoundsGep($stack, $depth)
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_export_array'),
            $buf,
            $htPtr,
            $level,
            $stack,
            $context->builder->add($depth, $i32->constInt(1, false))
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($defaultBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_ve_buf_append_cstr'),
            $buf,
            $context->builder->pointerCast($context->constantFromString('NULL'), $context->getTypeFromString('int8*'))
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
    }

    private static function branchIfArrayCycle(
        Context $context,
        LlvmFunction $fn,
        Value $ht,
        Value $stack,
        Value $depth,
        $cycleBb,
        $continueBb
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $idxSlot = $context->builder->alloca($sizeT, 1, 've_cycle_idx');
        $context->builder->store($zero, $idxSlot);
        $headBb = $fn->appendBasicBlock('ve_cycle_head');
        $bodyBb = $fn->appendBasicBlock('ve_cycle_body');
        $matchBb = $fn->appendBasicBlock('ve_cycle_match');
        $nextBb = $fn->appendBasicBlock('ve_cycle_next');
        $context->builder->branch($headBb);
        $context->builder->positionAtEnd($headBb);
        $idx = $context->builder->load($idxSlot);
        $doneScan = $context->builder->icmp(Builder::INT_SGE, $idx, $depth);
        $context->builder->branchIf($doneScan, $continueBb, $bodyBb);
        $context->builder->positionAtEnd($bodyBb);
        $seen = $context->builder->load($context->builder->inBoundsGep($stack, $idx));
        $isSame = $context->builder->icmp(Builder::INT_EQ, $seen, $ht);
        $context->builder->branchIf($isSame, $matchBb, $nextBb);
        $context->builder->positionAtEnd($matchBb);
        $context->builder->branch($cycleBb);
        $context->builder->positionAtEnd($nextBb);
        $context->builder->store(
            $context->builder->addNoUnsignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($headBb);
    }

    private static function emitCircularWarning(Context $context): void
    {
        $message = self::CIRCULAR_WARNING;
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private static function loadValueKind(Context $context, Value $valuePtr): Value
    {
        $map = $context->structFieldMap['__value__'];

        return $context->builder->and(
            $context->builder->load($context->builder->structGep($valuePtr, $map['type'])),
            $context->getTypeFromString('int8')->constInt(0x7f, false)
        );
    }

    private static function bufFieldPtr(Context $context, Value $bufVoid, int $offset, $fieldTy): Value
    {
        $buf = $context->builder->pointerCast($bufVoid, $context->getTypeFromString('int8*'));
        $slot = $context->builder->gep($buf, $context->getTypeFromString('int32')->constInt($offset, false));

        return $context->builder->pointerCast($slot, $fieldTy->pointerType(0));
    }

    /** @return array{ref: int, length: int, value: int} */
    private static function stringFieldMap(Context $context): array
    {
        return $context->structFieldMap['__string__'] ?? ['ref' => 0, 'length' => 1, 'value' => 2];
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
