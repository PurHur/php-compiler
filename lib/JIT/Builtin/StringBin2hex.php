<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for bin2hex() via Bin2hexJitHelper PHP (#14603, #18884).
 *
 * User-script standalone AOT: inline LLVM hex loop (#3357) — nested Bin2hexJitHelper
 * segfaults after minimal standalone init (same class as hash crypto defer).
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(bin2hex)
 */
final class StringBin2hex
{
    private const ABI = '__compiler_bin2hex';

    private const HELPER_PATH = '/ext/standard/Bin2hexJitHelper.php';

    private const BIN2HEX_HELPER = 'PHPCompiler\\ext\\standard\\Bin2hexJitHelper::bin2hexArgv';

    private const BRIDGE_ENTRY = 'bin2hex_bridge_entry';

    private const INLINE_ENTRY = 'bin2hex_inline_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BIN2HEX_HELPER,
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
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }
        if (null !== $probe && JitVmHelperLink::hasNamedBridgeEntry($probe, self::INLINE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            self::implementInlineLlvm($context, $probe);
        } else {
            self::implementBridge($context);
        }
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::BIN2HEX_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18884'
        );
    }

    private static function implementInlineLlvm(Context $context, ?LlvmFunction $probe): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($strPtr, false, $strPtr)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::INLINE_ENTRY);
        $context->builder->positionAtEnd($entry);

        $input = $fn->getParam(0);
        $map = $context->structFieldMap['__string__'];
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        $len = $context->builder->load($context->builder->structGep($input, $map['length']));
        $lenI64 = $context->builder->zExt($len, $i64);
        $hexLen = $context->builder->mul($lenI64, $i64->constInt(2, false));
        $hexStr = $context->builder->call($context->lookupFunction('__string__alloc'), $hexLen);
        $context->builder->store($hexLen, $context->builder->structGep($hexStr, $map['length']));
        $srcPtr = $context->builder->structGep($input, $map['value']);
        $destPtr = $context->builder->structGep($hexStr, $map['value']);
        $hexTable = $context->builder->pointerCast(
            $context->constantFromString('0123456789abcdef'),
            $charPtr
        );

        $idxSlot = $context->builder->alloca($i64, 1, 'b2h_idx');
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $loopHead = $fn->appendBasicBlock('b2h_inline_head');
        $loopBody = $fn->appendBasicBlock('b2h_inline_body');
        $loopDone = $fn->appendBasicBlock('b2h_inline_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $lenI64);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $idxI32 = $context->builder->truncOrBitCast($idx, $i32);
        $byte = $context->builder->load($context->builder->gep($srcPtr, $idx));
        $byteI32 = $context->builder->zExt($byte, $i32);
        $hi = $context->builder->lShr($byteI32, $i32->constInt(4, false));
        $lo = $context->builder->bitwiseAnd($byteI32, $i32->constInt(0x0F, false));
        $outPos = $context->builder->mulNoSignedWrap($idxI32, $i32->constInt(2, false));
        $context->builder->store(
            $context->builder->load($context->builder->gep($hexTable, $hi)),
            $context->builder->gep($destPtr, $outPos)
        );
        $context->builder->store(
            $context->builder->load($context->builder->gep($hexTable, $lo)),
            $context->builder->gep($destPtr, $context->builder->add($outPos, $i32->constInt(1, false)))
        );
        $context->builder->store($context->builder->add($idx, $i64->constInt(1, false)), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($hexStr);
        $context->registerFunction(self::ABI, $fn);
    }
}
