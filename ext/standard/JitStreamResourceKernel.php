<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamGlobalsJit;
use PHPCompiler\JIT\Builtin\StreamLifecycle;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM get_resource_type()/get_resources() helpers (#5179, #3646, #6821, #19613).
 *
 * Quarantined from lib/JIT/Builtin/StreamResourceJit — {@see \PHPCompiler\JIT\Builtin\StreamResource}
 * stays the thin orchestrator. Handle table stays in C until full #5343 migration.
 *
 * php-src: ext/standard/file.c, ext/standard/basic_functions.c
 */
final class JitStreamResourceKernel
{
    private const MAX_HANDLES = 256;

    private const GLOBAL_HANDLES = 'phpc_stream_handles';

    private const GLOBAL_WAS_USED = 'phpc_stream_was_used';

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_get_resource_type',
        '__compiler_get_resources',
    ];

    public static function implement(Context $context): void
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $probe = $context->module->getNamedFunction('__compiler_get_resource_type');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);

            return;
        }

        // Define stream table globals (with initializers) + is_resource ABI
        // so standalone AOT does not leave extern decls unresolved (#23342 / #3142).
        StreamGlobalsJit::ensureGlobals($context);
        StreamLifecycle::ensureLinked($context);
        self::ensureIsProcessResourceStub($context);
        self::ensureLibc($context);

        self::implementIfMissing($context, '__compiler_get_resource_type', self::emitGetResourceType(...));
        self::implementIfMissing($context, '__compiler_get_resources', self::emitGetResources(...));
        BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
    }

    /**
     * Lightweight ABI for process handles when ProcessOpenRuntime is not linked.
     * Returns 0 (not a process) so get_resource_type() stream path remains usable under AOT.
     */
    private static function ensureIsProcessResourceStub(Context $context): void
    {
        $name = '__compiler_is_process_resource';
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($name, $context->context->functionType($i32, false, $i64));
        $entry = $fn->appendBasicBlock('is_process_stub_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($i32->constInt(0, false));
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
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

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        $ft = match ($name) {
            '__compiler_get_resource_type' => $context->context->functionType($strPtr, false, $i64),
            '__compiler_get_resources' => $context->context->functionType($htPtr, false, $strPtr),
            default => throw new \LogicException('JitStreamResourceKernel: unknown function '.$name),
        };

        return $context->module->addFunction($name, $ft);
    }

    private static function emitGetResourceType(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('grt_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $three = $i64->constInt(3, false);
        $max = $i64->constInt(self::MAX_HANDLES, false);
        $zeroI32 = $i32->constInt(0, false);

        $isRes = $context->builder->call($context->lookupFunction('__compiler_is_resource'), $handle);
        $isOpen = $context->builder->icmp(Builder::INT_NE, $isRes, $zeroI32);
        $openBb = $fn->appendBasicBlock('grt_open');
        $closedBb = $fn->appendBasicBlock('grt_closed');
        $context->builder->branchIf($isOpen, $openBb, $closedBb);

        $context->builder->positionAtEnd($openBb);
        $isProcess = $context->builder->call($context->lookupFunction('__compiler_is_process_resource'), $handle);
        $isProcessRes = $context->builder->icmp(Builder::INT_NE, $isProcess, $zeroI32);
        $processBb = $fn->appendBasicBlock('grt_process');
        $streamBb = $fn->appendBasicBlock('grt_stream');
        $context->builder->branchIf($isProcessRes, $processBb, $streamBb);

        $context->builder->positionAtEnd($processBb);
        $processType = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(7, false),
            self::literalCstr($context, 'process')
        );
        $context->builder->returnValue($processType);

        $context->builder->positionAtEnd($streamBb);
        $streamType = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(6, false),
            self::literalCstr($context, 'stream')
        );
        $context->builder->returnValue($streamType);

        $context->builder->positionAtEnd($closedBb);
        $handleOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $handle, $three),
            $context->builder->icmp(Builder::INT_SLT, $handle, $max)
        );
        $checkUsedBb = $fn->appendBasicBlock('grt_check_used');
        $nullBb = $fn->appendBasicBlock('grt_null');
        $context->builder->branchIf($handleOk, $checkUsedBb, $nullBb);

        $context->builder->positionAtEnd($checkUsedBb);
        $wasUsed = self::loadWasUsedSlot($context, $handle);
        $wasUsedNonZero = $context->builder->icmp(Builder::INT_NE, $wasUsed, $i8->constInt(0, false));
        $unknownBb = $fn->appendBasicBlock('grt_unknown');
        $context->builder->branchIf($wasUsedNonZero, $unknownBb, $nullBb);

        $context->builder->positionAtEnd($unknownBb);
        $unknownType = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(7, false),
            self::literalCstr($context, 'Unknown')
        );
        $context->builder->returnValue($unknownType);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($nullStr);
    }

    private static function emitGetResources(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('grs_entry');
        $context->builder->positionAtEnd($entry);

        $typeFilter = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullHt = $htPtr->constNull();
        $nullStr = $strPtr->constNull();
        $zeroI32 = $i32->constInt(0, false);
        $six = $i64->constInt(6, false);

        $filterNull = $context->builder->icmp(Builder::INT_EQ, $typeFilter, $nullStr);
        $checkFilterBb = $fn->appendBasicBlock('grs_check_filter');
        $allocBb = $fn->appendBasicBlock('grs_alloc');
        $context->builder->branchIf($filterNull, $allocBb, $checkFilterBb);

        $context->builder->positionAtEnd($checkFilterBb);
        $strMap = $context->structFieldMap['__string__'];
        $filterLen = $context->builder->load($context->builder->structGep($typeFilter, $strMap['length']));
        $lenOk = $context->builder->icmp(Builder::INT_EQ, $filterLen, $six);
        $cmpBb = $fn->appendBasicBlock('grs_cmp');
        $invalidBb = $fn->appendBasicBlock('grs_invalid');
        $context->builder->branchIf($lenOk, $cmpBb, $invalidBb);

        $context->builder->positionAtEnd($cmpBb);
        $filterBytes = $context->builder->structGep($typeFilter, $strMap['value']);
        $filterCstr = $context->builder->pointerCast($filterBytes, $context->getTypeFromString('int8*'));
        $cmp = $context->builder->call(
            $context->lookupFunction('memcmp'),
            $filterCstr,
            self::literalCstr($context, 'stream'),
            $sizeT->constInt(6, false)
        );
        $cmpOk = $context->builder->icmp(Builder::INT_EQ, $cmp, $zeroI32);
        $context->builder->branchIf($cmpOk, $allocBb, $invalidBb);

        $context->builder->positionAtEnd($invalidBb);
        $context->builder->returnValue($nullHt);

        $context->builder->positionAtEnd($allocBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $nullHt);
        $failBb = $fn->appendBasicBlock('grs_fail');
        $loopInitBb = $fn->appendBasicBlock('grs_loop_init');
        $context->builder->branchIf($htNull, $failBb, $loopInitBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullHt);

        $loopCheckBb = $fn->appendBasicBlock('grs_loop_check');
        $loopBodyBb = $fn->appendBasicBlock('grs_body');
        $loopSkipBb = $fn->appendBasicBlock('grs_skip');
        $loopIncBb = $fn->appendBasicBlock('grs_inc');
        $doneBb = $fn->appendBasicBlock('grs_done');

        $context->builder->positionAtEnd($loopInitBb);
        $context->builder->branch($loopCheckBb);

        $context->builder->positionAtEnd($loopCheckBb);
        $idPhi = $context->builder->phi($i64, 'grs_id');
        $indexPhi = $context->builder->phi($sizeT, 'grs_index');
        $idPhi->addIncoming($i64->constInt(3, false), $loopInitBb);
        $indexPhi->addIncoming($sizeT->constInt(1, false), $loopInitBb);
        $maxId = $i64->constInt(self::MAX_HANDLES, false);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idPhi, $maxId);
        $context->builder->branchIf($atEnd, $doneBb, $loopBodyBb);

        $context->builder->positionAtEnd($loopBodyBb);
        $fp = self::loadTableSlot($context, self::GLOBAL_HANDLES, $idPhi);
        $i8p = $context->getTypeFromString('int8*');
        $slotOpen = $context->builder->icmp(Builder::INT_NE, $fp, $i8p->constNull());
        $storeBb = $fn->appendBasicBlock('grs_store');
        $context->builder->branchIf($slotOpen, $storeBb, $loopSkipBb);

        $context->builder->positionAtEnd($storeBb);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $ht,
            $indexPhi,
            $idPhi
        );
        $storedIndex = $context->builder->add($indexPhi, $sizeT->constInt(1, false));
        $context->builder->branch($loopIncBb);

        $context->builder->positionAtEnd($loopSkipBb);
        $context->builder->branch($loopIncBb);

        $context->builder->positionAtEnd($loopIncBb);
        $indexNextPhi = $context->builder->phi($sizeT, 'grs_index_next');
        $indexNextPhi->addIncoming($storedIndex, $storeBb);
        $indexNextPhi->addIncoming($indexPhi, $loopSkipBb);
        $nextId = $context->builder->add($idPhi, $i64->constInt(1, false));
        $idPhi->addIncoming($nextId, $loopIncBb);
        $indexPhi->addIncoming($indexNextPhi, $loopIncBb);
        $context->builder->branch($loopCheckBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($ht);
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');

        // memcmp(3) via LibcExtern::ensureMemcmpDecl after always-on drop (#31954);
        // canonical i8* ABI avoids void* NestedJIT mistyped calls (#27663).
        LibcExtern::ensureMemcmpDecl($context);
        foreach (
            [
                ['__string__init', $strPtr, [$i64, $i8p]],
                ['__hashtable__alloc', $htPtr, []],
                ['__hashtable__setLongAt', $context->getTypeFromString('void'), [$htPtr, $sizeT, $i64]],
                ['__compiler_is_resource', $i32, [$i64]],
                ['__compiler_is_process_resource', $i32, [$i64]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
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

    private static function ensureExternGlobals(Context $context): void
    {
        StreamGlobalsJit::ensureGlobals($context);
    }

    private static function loadTableSlot(Context $context, string $globalName, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('JitStreamResourceKernel: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);

        return $context->builder->load($context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    private static function loadWasUsedSlot(Context $context, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal(self::GLOBAL_WAS_USED);
        if (null === $global) {
            throw new \LogicException('JitStreamResourceKernel: '.self::GLOBAL_WAS_USED.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);

        return $context->builder->load($slot);
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast($context->constantFromString($text), $i8p);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after JitStreamResourceKernel implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
