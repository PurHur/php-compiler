<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * AOT headers_sent guard for header() — Warning + early return (php-src ext/standard/head.c, #34513).
 *
 * Tracks first body-output origin for Zend-shaped "output started at file:line" text and reads
 * {@see ObStorageLlvm} / {@see PendingHeadersJitBridge} `__phpc_sapi_output_started` directly so thin
 * `__phpc_headers_sent` stubs cannot mask body output (#20932 / #28929 peer).
 */
final class SapiHeaderGuardJit
{
    private const G_SAPI_STARTED = '__phpc_sapi_output_started';

    private const G_ORIGIN_FILE = '__phpc_sapi_output_origin_file';

    private const G_ORIGIN_LINE = '__phpc_sapi_output_origin_line';

    private static int $emitSerial = 0;

    public static function ensureOriginAbis(Context $context): void
    {
        self::ensureOriginGlobals($context);
        self::implementNoteOutputOrigin($context);
    }

    /** Stamp first echo/print site before ob_* append (php-src sapi_headers_sent origin). */
    public static function emitNoteOutputOrigin(Context $context, int $line): void
    {
        if ($line <= 0) {
            return;
        }
        $path = $context->jitAotEntryScriptPath;
        if ('' === $path) {
            return;
        }
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureOriginAbis($context);
        BasicBlockHelper::restoreInsertBlock($context, $saved);
        if (null === BasicBlockHelper::tryGetInsertBlock($context)) {
            return;
        }
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__phpc_sapi_note_output_origin'),
            $context->builder->pointerCast($context->constantFromString($path), $i8p),
            $i32->constInt($line, false)
        );
    }

    /**
     * Emit header() body only when SAPI output has not started; otherwise Warning (#34513).
     *
     * @param callable(): void $emitAdd
     */
    public static function emitGuardedHeader(Context $context, callable $emitAdd): Value
    {
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureOriginAbis($context);
        BasicBlockHelper::restoreInsertBlock($context, $saved);
        StringTriggerError::ensureLinked($context);

        $i32 = $context->getTypeFromString('int32');
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof LlvmFunction);
        ++self::$emitSerial;
        $sid = (string) self::$emitSerial;

        $bbSent = $fn->appendBasicBlock('hdr_sent_'.$sid);
        $bbOk = $fn->appendBasicBlock('hdr_ok_'.$sid);
        $bbDone = $fn->appendBasicBlock('hdr_done_'.$sid);

        $sent = self::emitLoadHeadersSentFlag($context);
        $isSent = $context->builder->icmp(Builder::INT_NE, $sent, $i32->constInt(0, false));
        $context->builder->branchIf($isSent, $bbSent, $bbOk);

        $context->builder->positionAtEnd($bbSent);
        self::emitWarnHeadersAlreadySent($context);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbOk);
        $emitAdd();
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return $i32->constInt(0, false);
    }

    private static function ensureOriginGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        if (null === $context->module->getNamedGlobal(self::G_SAPI_STARTED)) {
            $g = $context->module->addGlobal($i32, self::G_SAPI_STARTED);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_ORIGIN_FILE)) {
            $g = $context->module->addGlobal($i8p, self::G_ORIGIN_FILE);
            $g->setInitializer($i8p->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_ORIGIN_LINE)) {
            $g = $context->module->addGlobal($i32, self::G_ORIGIN_LINE);
            $g->setInitializer($i32->constInt(0, false));
        }
    }

    private static function implementNoteOutputOrigin(Context $context): void
    {
        $abi = '__phpc_sapi_note_output_origin';
        $probe = $context->module->getNamedFunction($abi);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abi, $probe);

            return;
        }

        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($voidTy, false, $i8p, $i32);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abi, $ft);
        if ($fn->countBasicBlocks() > 0) {
            $context->registerFunction($abi, $fn);

            return;
        }

        $entry = $fn->appendBasicBlock('sapi_note_origin_entry');
        $skip = $fn->appendBasicBlock('sapi_note_origin_skip');
        $store = $fn->appendBasicBlock('sapi_note_origin_store');
        $done = $fn->appendBasicBlock('sapi_note_origin_done');
        $context->builder->positionAtEnd($entry);

        $file = $fn->getParam(0);
        $line = $fn->getParam(1);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $file, $i8p->constNull());
        $context->builder->branchIf($isNull, $done, $skip);

        $context->builder->positionAtEnd($skip);
        $originPtr = $context->builder->pointerCast(
            $context->module->getNamedGlobal(self::G_ORIGIN_FILE),
            $i8p->pointerType(0)
        );
        $existing = $context->builder->load($originPtr);
        $hasOrigin = $context->builder->icmp(Builder::INT_NE, $existing, $i8p->constNull());
        $context->builder->branchIf($hasOrigin, $done, $store);

        $context->builder->positionAtEnd($store);
        $context->builder->store($file, $originPtr);
        $linePtr = $context->builder->pointerCast(
            $context->module->getNamedGlobal(self::G_ORIGIN_LINE),
            $i32->pointerType(0)
        );
        $context->builder->store($line, $linePtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->registerFunction($abi, $fn);
        BasicBlockHelper::restoreInsertBlock($context, $saved);
    }

    /** @return Value i32 — nonzero when SAPI body output has started */
    private static function emitLoadHeadersSentFlag(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');
        self::ensureOriginGlobals($context);

        return $context->builder->load(
            $context->builder->pointerCast(
                $context->module->getNamedGlobal(self::G_SAPI_STARTED),
                $i32->pointerType(0)
            )
        );
    }

    private static function emitWarnHeadersAlreadySent(Context $context): void
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringTriggerError::ensureLinked($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

        LibcExtern::ensureSnprintf($context);
        LibcExtern::ensureMallocFamily($context);

        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');

        $prefix = 'Cannot modify header information - headers already sent by (output started at %s:%d)';
        $bufSize = $sizeT->constInt(512, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);

        $originFilePtr = $context->builder->pointerCast(
            $context->module->getNamedGlobal(self::G_ORIGIN_FILE),
            $i8p->pointerType(0)
        );
        $originFile = $context->builder->load($originFilePtr);
        $originLinePtr = $context->builder->pointerCast(
            $context->module->getNamedGlobal(self::G_ORIGIN_LINE),
            $i32->pointerType(0)
        );
        $originLine = $context->builder->load($originLinePtr);

        $fmt = $context->builder->pointerCast(
            $context->constantFromString($prefix),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $originFile,
            $originLine
        );

        $callPath = $context->jitAotEntryScriptPath;
        $callFile = $context->builder->pointerCast(
            $context->constantFromString('' !== $callPath ? $callPath : 'Standard input code'),
            $i8p
        );
        $callLine = $i32->constInt(max(0, $context->callSiteLine), false);

        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $context->builder->pointerCast($bufChar, $i8p),
            $context->builder->zExt($written, $sizeT),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $callFile,
            $callLine
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);
    }
}
