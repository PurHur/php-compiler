<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\Call\Vararg;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ExceptionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_;

/**
 * Print Zend-shaped uncaught user throw fatals for JIT/AOT (#23641, #36394).
 *
 * php-src: Zend/zend_exceptions.c — zend_exception_error / uncaught Throwable
 */
final class UncaughtThrowPrinter
{
    /**
     * fprintf(stderr, Zend uncaught shape) then exit(255). Replaces silent abort().
     *
     * Straight-line IR: helpers that clearInsertionPosition must not leave us in a
     * terminated block before exit(255) (#23641).
     */
    public static function emitPrintAndExit(Context $context, Value $exceptionObj): void
    {
        self::ensureLibcDecls($context);
        $builder = $context->builder;
        $startBb = BasicBlockHelper::tryGetInsertBlock($context);
        if (null === $startBb || !$startBb->getParent() instanceof Function_) {
            throw new \LogicException('UncaughtThrowPrinter requires parent function');
        }

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $objPtrTy = $context->getTypeFromString('__object__*');

        $emptyCstr = self::cstrPtr($context, '');
        $unknownCstr = self::cstrPtr($context, 'Unknown');
        $excCstr = self::cstrPtr($context, 'Exception');

        $isNullObj = $builder->icmp(Builder::INT_EQ, $exceptionObj, $objPtrTy->constNull());
        $objMap = $context->structFieldMap['__object__'];
        $classId = $builder->load($builder->structGep($exceptionObj, $objMap['class_id']));
        // Prefer compile-unit class map (no NestedJIT GetClass helper — avoids insert wipe #23641).
        $classCstr = self::classNameCstrFromId($context, $classId, $excCstr);
        $classCstr = $builder->select($isNullObj, $excCstr, $classCstr);

        $msgStr = self::tryReadStringProp($context, $exceptionObj, ExceptionSupport::PROP_MESSAGE);
        $msgCstr = self::stringDataOrFallback($context, $msgStr, $emptyCstr);
        $msgCstr = $builder->select($isNullObj, $emptyCstr, $msgCstr);

        $fileStr = self::tryReadStringProp($context, $exceptionObj, ExceptionSupport::PROP_FILE);
        $fileCstr = self::stringDataOrFallback($context, $fileStr, $unknownCstr);
        $fileCstr = $builder->select($isNullObj, $unknownCstr, $fileCstr);

        $lineLong = self::tryReadLongProp($context, $exceptionObj, ExceptionSupport::PROP_LINE);
        $lineI32 = $builder->trunc($lineLong, $i32);
        $zeroI32 = $i32->constInt(0, false);
        $lineOut = $builder->select($isNullObj, $zeroI32, $lineI32);

        $lineBuf = $builder->alloca($i8->arrayType(1024), 1, 'uncaught_line');
        $linePtr = $builder->pointerCast($lineBuf, $i8p);
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        LibcExtern::ensureSnprintf($context);
        $builder->call(
            $context->lookupFunction('snprintf'),
            $linePtr,
            $context->constantFromInteger(1024, 'size_t'),
            self::cstrPtr($context, "PHP Fatal error:  Uncaught %s: %s in %s:%d\n"),
            $classCstr,
            $msgCstr,
            $fileCstr,
            $lineOut
        );
        $stderr = StringTriggerErrorJit::stderrFilePtr($context);
        $builder->call(
            $context->lookupFunction('fprintf'),
            $stderr,
            self::cstrPtr($context, '%s'),
            $linePtr
        );
        $traceCstr = self::emitFormatTraceCstr($context);
        $builder->call(
            $context->lookupFunction('fprintf'),
            $stderr,
            self::cstrPtr($context, "Stack trace:\n%s  thrown in %s on line %d\n"),
            $traceCstr,
            $fileCstr,
            $lineOut
        );
        // php-src main/main.c php_error_cb + sapi/cli: display_errors=On mirrors the fatal
        // to stdout without the "PHP " prefix (one space after "Fatal error:") (#36383).
        $stdout = self::stdoutFilePtr($context);
        $builder->call(
            $context->lookupFunction('fprintf'),
            $stdout,
            self::cstrPtr($context, "\nFatal error: Uncaught %s: %s in %s:%d\nStack trace:\n%s  thrown in %s on line %d\n"),
            $classCstr,
            $msgCstr,
            $fileCstr,
            $lineOut,
            $traceCstr,
            $fileCstr,
            $lineOut
        );
        $builder->call($context->lookupFunction('exit'), $i32->constInt(255, false));
        $context->llvm->lib->LLVMBuildUnreachable($builder->builder);
    }

    public const STACK_MAX = 64;

    private const G_SP = 'phpc_ex_stack_sp';

    private const G_FILE = 'phpc_ex_stack_file';

    private const G_LINE = 'phpc_ex_stack_line';

    private const G_FUNC = 'phpc_ex_stack_func';

    private const G_SNAP_SP = 'phpc_ex_snap_sp';

    private const G_SNAP_FILE = 'phpc_ex_snap_file';

    private const G_SNAP_LINE = 'phpc_ex_snap_line';

    private const G_SNAP_FUNC = 'phpc_ex_snap_func';

    private const G_TRACE_BUF = 'phpc_ex_trace_buf';

    private const FN_PUSH = 'phpc_ex_stack_push';

    private const FN_POP = 'phpc_ex_stack_pop';

    private const FN_SNAP = 'phpc_ex_stack_snapshot';

    private const FN_FORMAT = 'phpc_ex_format_uncaught_trace';

    /**
     * User PHP frames for Zend uncaught traces (#36394).
     *
     * php-src: Zend/zend_exceptions.c zend_fetch_debug_backtrace
     */
    public static function shouldTrackCall(Context $context, Call $toCall): bool
    {
        if (NestedJitCompileScope::isActive()) {
            return false;
        }
        $name = self::callDisplayName($toCall);
        if ('' === $name || '{main}' === $name) {
            return false;
        }
        $lc = strtolower($name);
        if (str_starts_with($lc, 'phpcompiler\\')) {
            return false;
        }
        if (str_starts_with($lc, '__phpc') || str_starts_with($lc, 'phpc_') || str_starts_with($lc, '__compiler')) {
            return false;
        }

        return $toCall instanceof Native || $toCall instanceof Vararg;
    }

    public static function emitPushFrame(Context $context, Call $toCall): void
    {
        self::ensureStackRuntime($context);
        $name = self::callDisplayName($toCall);
        if (preg_match('/^\{anonymous\}#\d+$/', $name)) {
            $name = '{closure}';
        }
        $file = $context->jitAotEntryScriptPath;
        if ('' === $file) {
            $file = 'Unknown';
        }
        $line = max(0, $context->callSiteLine);
        $context->builder->call(
            $context->lookupFunction(self::FN_PUSH),
            self::cstrPtr($context, $file),
            $context->getTypeFromString('int32')->constInt($line, false),
            self::cstrPtr($context, $name)
        );
    }

    public static function emitPopFrame(Context $context): void
    {
        self::ensureStackRuntime($context);
        $context->builder->call($context->lookupFunction(self::FN_POP));
    }

    public static function emitSnapshot(Context $context): void
    {
        self::ensureStackRuntime($context);
        $context->builder->call($context->lookupFunction(self::FN_SNAP));
    }

    private static function emitFormatTraceCstr(Context $context): Value
    {
        self::ensureStackRuntime($context);

        return $context->builder->call($context->lookupFunction(self::FN_FORMAT));
    }

    private static function callDisplayName(Call $toCall): string
    {
        if ($toCall instanceof Native || $toCall instanceof Vararg) {
            return (string) $toCall->name;
        }

        return '';
    }

    private static function ensureStackRuntime(Context $context): void
    {
        self::ensureStackGlobals($context);
        self::ensureLibcDecls($context);
        LibcExtern::ensureMemcpyImplemented($context);
        self::implementPush($context);
        self::implementPop($context);
        self::implementSnapshot($context);
        self::implementFormat($context);
    }

    private static function ensureStackGlobals(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ptrArr = $i8p->arrayType(self::STACK_MAX);
        $i32Arr = $i32->arrayType(self::STACK_MAX);
        $bufTy = $i8->arrayType(4096);
        foreach (
            [
                self::G_SP => $i32,
                self::G_SNAP_SP => $i32,
            ] as $name => $ty
        ) {
            if (null === $context->module->getNamedGlobal($name)) {
                $g = $context->module->addGlobal($ty, $name);
                $g->setInitializer($i32->constInt(0, false));
            }
        }
        foreach (
            [
                self::G_FILE => $ptrArr,
                self::G_FUNC => $ptrArr,
                self::G_SNAP_FILE => $ptrArr,
                self::G_SNAP_FUNC => $ptrArr,
            ] as $name => $ty
        ) {
            if (null === $context->module->getNamedGlobal($name)) {
                $g = $context->module->addGlobal($ty, $name);
                $g->setInitializer($ty->constNull());
            }
        }
        foreach ([self::G_LINE => $i32Arr, self::G_SNAP_LINE => $i32Arr] as $name => $ty) {
            if (null === $context->module->getNamedGlobal($name)) {
                $g = $context->module->addGlobal($ty, $name);
                $g->setInitializer($ty->constNull());
            }
        }
        if (null === $context->module->getNamedGlobal(self::G_TRACE_BUF)) {
            $g = $context->module->addGlobal($bufTy, self::G_TRACE_BUF);
            $g->setInitializer($bufTy->constNull());
        }
    }

    private static function captureInsert(?Context $context): mixed
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsert(Context $context, mixed $saved): void
    {
        if (null !== $saved) {
            $context->builder->positionAtEnd($saved);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function arrayElemPtr(Context $context, string $globalName, $elemType, Value $index): Value
    {
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('uncaught stack global missing: '.$globalName);
        }
        $arrayPtr = $context->builder->pointerCast($global, $elemType->pointerType(0)->pointerType(0));
        $elemPtr = $context->builder->inBoundsGEP($arrayPtr, $index);

        return $context->builder->pointerCast($elemPtr, $elemType->pointerType(0));
    }

    private static function i32GlobalPtr(Context $context, string $name): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('uncaught stack global missing: '.$name);
        }

        return $context->builder->pointerCast($global, $i32->pointerType(0));
    }

    private static function implementPush(Context $context): void
    {
        $existing = $context->module->getNamedFunction(self::FN_PUSH);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction(self::FN_PUSH, $existing);

            return;
        }
        $saved = self::captureInsert($context);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $void = $context->context->voidType();
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($void, false, $i8p, $i32, $i8p);
        $fn = null !== $existing ? $existing : $context->module->addFunction(self::FN_PUSH, $ft);
        $entry = $fn->appendBasicBlock('ex_push_entry');
        $ok = $fn->appendBasicBlock('ex_push_ok');
        $done = $fn->appendBasicBlock('ex_push_done');
        $b = $context->builder;
        $b->positionAtEnd($entry);
        $spPtr = self::i32GlobalPtr($context, self::G_SP);
        $sp = $b->load($spPtr);
        $full = $b->icmp(Builder::INT_SGE, $sp, $i32->constInt(self::STACK_MAX, false));
        $b->branchIf($full, $done, $ok);
        $b->positionAtEnd($ok);
        $idx = $b->zext($sp, $i64);
        $b->store($fn->getParam(0), self::arrayElemPtr($context, self::G_FILE, $i8p, $idx));
        $b->store($fn->getParam(1), self::arrayElemPtr($context, self::G_LINE, $i32, $idx));
        $b->store($fn->getParam(2), self::arrayElemPtr($context, self::G_FUNC, $i8p, $idx));
        $b->store($b->add($sp, $i32->constInt(1, false)), $spPtr);
        $b->branch($done);
        $b->positionAtEnd($done);
        $b->returnVoid();
        $context->registerFunction(self::FN_PUSH, $fn);
        self::restoreInsert($context, $saved);
    }

    private static function implementPop(Context $context): void
    {
        $existing = $context->module->getNamedFunction(self::FN_POP);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction(self::FN_POP, $existing);

            return;
        }
        $saved = self::captureInsert($context);
        $i32 = $context->getTypeFromString('int32');
        $void = $context->context->voidType();
        $ft = $context->context->functionType($void, false);
        $fn = null !== $existing ? $existing : $context->module->addFunction(self::FN_POP, $ft);
        $entry = $fn->appendBasicBlock('ex_pop_entry');
        $ok = $fn->appendBasicBlock('ex_pop_ok');
        $done = $fn->appendBasicBlock('ex_pop_done');
        $b = $context->builder;
        $b->positionAtEnd($entry);
        $spPtr = self::i32GlobalPtr($context, self::G_SP);
        $sp = $b->load($spPtr);
        $empty = $b->icmp(Builder::INT_SLE, $sp, $i32->constInt(0, false));
        $b->branchIf($empty, $done, $ok);
        $b->positionAtEnd($ok);
        $b->store($b->sub($sp, $i32->constInt(1, false)), $spPtr);
        $b->branch($done);
        $b->positionAtEnd($done);
        $b->returnVoid();
        $context->registerFunction(self::FN_POP, $fn);
        self::restoreInsert($context, $saved);
    }

    private static function implementSnapshot(Context $context): void
    {
        $existing = $context->module->getNamedFunction(self::FN_SNAP);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction(self::FN_SNAP, $existing);

            return;
        }
        $saved = self::captureInsert($context);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $void = $context->context->voidType();
        $ft = $context->context->functionType($void, false);
        $fn = null !== $existing ? $existing : $context->module->addFunction(self::FN_SNAP, $ft);
        $entry = $fn->appendBasicBlock('ex_snap_entry');
        $b = $context->builder;
        $b->positionAtEnd($entry);
        $sp = $b->load(self::i32GlobalPtr($context, self::G_SP));
        $b->store($sp, self::i32GlobalPtr($context, self::G_SNAP_SP));
        $ptrBytes = $sizeT->constInt(self::STACK_MAX * 8, false);
        $i32Bytes = $sizeT->constInt(self::STACK_MAX * 4, false);
        $memcpy = $context->lookupFunction('memcpy');
        $b->call(
            $memcpy,
            $b->pointerCast($context->module->getNamedGlobal(self::G_SNAP_FILE), $i8p),
            $b->pointerCast($context->module->getNamedGlobal(self::G_FILE), $i8p),
            $ptrBytes
        );
        $b->call(
            $memcpy,
            $b->pointerCast($context->module->getNamedGlobal(self::G_SNAP_FUNC), $i8p),
            $b->pointerCast($context->module->getNamedGlobal(self::G_FUNC), $i8p),
            $ptrBytes
        );
        $b->call(
            $memcpy,
            $b->pointerCast($context->module->getNamedGlobal(self::G_SNAP_LINE), $i8p),
            $b->pointerCast($context->module->getNamedGlobal(self::G_LINE), $i8p),
            $i32Bytes
        );
        $b->returnVoid();
        $context->registerFunction(self::FN_SNAP, $fn);
        self::restoreInsert($context, $saved);
    }

    private static function implementFormat(Context $context): void
    {
        $existing = $context->module->getNamedFunction(self::FN_FORMAT);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction(self::FN_FORMAT, $existing);

            return;
        }
        $saved = self::captureInsert($context);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($i8p, false);
        $fn = null !== $existing ? $existing : $context->module->addFunction(self::FN_FORMAT, $ft);
        $entry = $fn->appendBasicBlock('ex_fmt_entry');
        $loop = $fn->appendBasicBlock('ex_fmt_loop');
        $body = $fn->appendBasicBlock('ex_fmt_body');
        $after = $fn->appendBasicBlock('ex_fmt_after');
        $b = $context->builder;
        $b->positionAtEnd($entry);
        $bufG = $context->module->getNamedGlobal(self::G_TRACE_BUF);
        $buf = $b->pointerCast($bufG, $i8p);
        $off = $b->alloca($i32);
        $idx = $b->alloca($i32);
        $cur = $b->alloca($i32);
        $b->store($i32->constInt(0, false), $off);
        $b->store($i32->constInt(0, false), $idx);
        $sp = $b->load(self::i32GlobalPtr($context, self::G_SNAP_SP));
        $b->store($b->sub($sp, $i32->constInt(1, false)), $cur);
        $b->branch($loop);

        $b->positionAtEnd($loop);
        $i = $b->load($cur);
        $cont = $b->icmp(Builder::INT_SGE, $i, $i32->constInt(0, false));
        $b->branchIf($cont, $body, $after);

        $b->positionAtEnd($body);
        $i64i = $b->zext($i, $i64);
        $file = $b->load(self::arrayElemPtr($context, self::G_SNAP_FILE, $i8p, $i64i));
        $line = $b->load(self::arrayElemPtr($context, self::G_SNAP_LINE, $i32, $i64i));
        $func = $b->load(self::arrayElemPtr($context, self::G_SNAP_FUNC, $i8p, $i64i));
        $offV = $b->load($off);
        $dst = $b->gep($buf, $b->zext($offV, $i64));
        $remain = $b->sub($i32->constInt(4000, false), $offV);
        $remainSz = $b->zext($remain, $sizeT);
        $n = $b->call(
            $context->lookupFunction('snprintf'),
            $dst,
            $remainSz,
            self::cstrPtr($context, "#%d %s(%d): %s()\n"),
            $b->load($idx),
            $file,
            $line,
            $func
        );
        $b->store($b->add($offV, $n), $off);
        $b->store($b->add($b->load($idx), $i32->constInt(1, false)), $idx);
        $b->store($b->sub($i, $i32->constInt(1, false)), $cur);
        $b->branch($loop);

        $b->positionAtEnd($after);
        $offV2 = $b->load($off);
        $dst2 = $b->gep($buf, $b->zext($offV2, $i64));
        $remain2 = $b->zext($b->sub($i32->constInt(4000, false), $offV2), $sizeT);
        $b->call(
            $context->lookupFunction('snprintf'),
            $dst2,
            $remain2,
            self::cstrPtr($context, "#%d {main}\n"),
            $b->load($idx)
        );
        $b->returnValue($buf);
        $context->registerFunction(self::FN_FORMAT, $fn);
        self::restoreInsert($context, $saved);
    }

    /** Inline class_id → name cstr from the current Object_ map (no NestedJIT). */
    private static function classNameCstrFromId(Context $context, Value $classId, Value $fallback): Value
    {
        $builder = $context->builder;
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $names = $context->type->object->allClassNamesById();
        if ([] === $names) {
            return $fallback;
        }
        $func = BasicBlockHelper::parentFunction($context);
        $join = $func->appendBasicBlock('uncaught_cn_join');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $builder->store($fallback, $resultSlot);
        $check = $builder->getInsertBlock();
        $lastId = array_key_last($names);
        foreach ($names as $id => $name) {
            $builder->positionAtEnd($check);
            $matchBb = $func->appendBasicBlock('uncaught_cn_'.$id);
            $nextBb = ((int) $id === (int) $lastId)
                ? $join
                : $func->appendBasicBlock('uncaught_cn_try_'.$id);
            $eq = $builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt((int) $id, false)
            );
            $builder->branchIf($eq, $matchBb, $nextBb);
            $builder->positionAtEnd($matchBb);
            $builder->store(self::cstrPtr($context, $name), $resultSlot);
            $builder->branch($join);
            $check = $nextBb;
        }
        $builder->positionAtEnd($join);

        return $builder->load($resultSlot);
    }

    private static function stringDataOrFallback(Context $context, Value $strPtr, Value $fallbackCstr): Value
    {
        $builder = $context->builder;
        $i8p = $context->getTypeFromString('int8*');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $func = BasicBlockHelper::parentFunction($context);
        $nullBb = $func->appendBasicBlock('uncaught_str_null');
        $dataBb = $func->appendBasicBlock('uncaught_str_data');
        $joinBb = $func->appendBasicBlock('uncaught_str_join');
        $isNull = $builder->icmp(Builder::INT_EQ, $strPtr, $strPtrTy->constNull());
        $builder->branchIf($isNull, $nullBb, $dataBb);

        $builder->positionAtEnd($nullBb);
        $builder->branch($joinBb);

        $builder->positionAtEnd($dataBb);
        $strMap = $context->structFieldMap['__string__'];
        $data = $builder->pointerCast(
            $builder->structGep($strPtr, $strMap['value']),
            $i8p
        );
        $builder->branch($joinBb);

        $builder->positionAtEnd($joinBb);
        $phi = $builder->phi($i8p);
        $phi->addIncoming($fallbackCstr, $nullBb);
        $phi->addIncoming($data, $dataBb);

        return $phi;
    }

    private static function tryReadStringProp(Context $context, Value $obj, string $prop): Value
    {
        $strPtrTy = $context->getTypeFromString('__string__*');
        $insert = BasicBlockHelper::tryGetInsertBlock($context);
        foreach (['Exception', 'Error'] as $class) {
            try {
                $classId = $context->type->object->lookup($class);
            } catch (\Throwable) {
                if (null !== $insert) {
                    BasicBlockHelper::restoreInsertBlock($context, $insert);
                }
                continue;
            }
            if (!$context->type->object->hasProperty($classId, $prop)) {
                continue;
            }
            if (null !== $insert) {
                BasicBlockHelper::restoreInsertBlock($context, $insert);
            }
            $fetched = $context->type->object->propertyFetch($obj, $class, $prop);
            if (null !== $insert) {
                BasicBlockHelper::restoreInsertBlock($context, $insert);
            }
            if (Variable::TYPE_STRING === $fetched->type) {
                return $context->helper->loadValue($fetched);
            }
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $fetched);

            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $valuePtr
            );
        }
        if (null !== $insert) {
            BasicBlockHelper::restoreInsertBlock($context, $insert);
        }

        return $strPtrTy->constNull();
    }

    private static function tryReadLongProp(Context $context, Value $obj, string $prop): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $insert = BasicBlockHelper::tryGetInsertBlock($context);
        foreach (['Exception', 'Error'] as $class) {
            try {
                $classId = $context->type->object->lookup($class);
            } catch (\Throwable) {
                if (null !== $insert) {
                    BasicBlockHelper::restoreInsertBlock($context, $insert);
                }
                continue;
            }
            if (!$context->type->object->hasProperty($classId, $prop)) {
                continue;
            }
            if (null !== $insert) {
                BasicBlockHelper::restoreInsertBlock($context, $insert);
            }
            $fetched = $context->type->object->propertyFetch($obj, $class, $prop);
            if (null !== $insert) {
                BasicBlockHelper::restoreInsertBlock($context, $insert);
            }
            if (Variable::TYPE_NATIVE_LONG === $fetched->type) {
                return $context->helper->loadValue($fetched);
            }
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $fetched);

            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $valuePtr
            );
        }
        if (null !== $insert) {
            BasicBlockHelper::restoreInsertBlock($context, $insert);
        }

        return $i64->constInt(0, false);
    }

    private static function stdoutFilePtr(Context $context): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        if (null === $context->module->getNamedGlobal('stdout')) {
            $context->module->addGlobal($i8p, 'stdout');
        }
        $stdoutGlobal = $context->module->getNamedGlobal('stdout');

        return $context->builder->load(
            $context->builder->pointerCast($stdoutGlobal, $i8p->pointerType(0))
        );
    }

    private static function cstrPtr(Context $context, string $literal): Value
    {
        return $context->builder->pointerCast(
            $context->constantFromString($literal),
            $context->getTypeFromString('int8*')
        );
    }

    private static function ensureLibcDecls(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $void = $context->context->voidType();

        if (null === $context->module->getNamedGlobal('stderr')) {
            $context->module->addGlobal($i8p, 'stderr');
        }
        if (null === $context->module->getNamedGlobal('stdout')) {
            $context->module->addGlobal($i8p, 'stdout');
        }

        TypeErrorRaise::ensureDeclInScope(
            $context,
            'fprintf',
            $context->context->functionType($i32, true, $i8p, $i8p)
        );
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'snprintf',
            $context->context->functionType($i32, true, $i8p, $sizeT, $i8p)
        );
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'exit',
            $context->context->functionType($void, false, $i32)
        );
    }
}
