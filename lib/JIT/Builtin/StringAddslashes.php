<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for addslashes() (#14741, #18391, #26907).
 *
 * Embed/JIT: NestedJIT {@see \PHPCompiler\ext\standard\AddslashesJitHelper}.
 * Thin standalone AOT: pure LLVM (NestedJIT of VmString::addslashes segfaults — #26907 / peer #27574).
 * SSOT: {@see \PHPCompiler\ext\standard\VmString::addslashes()}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(addslashes) / php_addslashes
 */
final class StringAddslashes
{
    private const ABI = '__string__addslashes';

    private const HELPER_PATH = '/ext/standard/AddslashesJitHelper.php';

    private const ADDSLASHES_HELPER = 'PHPCompiler\\ext\\standard\\AddslashesJitHelper::addslashesArgv';

    private const BRIDGE_ENTRY = 'addslashes_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ADDSLASHES_HELPER,
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
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();

        if ($context->isThinStandaloneAotMain()) {
            self::implementThinLlvm($context);
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
            self::ADDSLASHES_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18391'
        );
    }

    /**
     * Thin AOT: escape \\ ' " and NUL→\0 in pure LLVM (#26907).
     * Matches {@see \PHPCompiler\ext\standard\VmString::addslashes()}.
     */
    private static function implementThinLlvm(Context $context): void
    {
        StringDir::ensureLinked($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $map = $context->structFieldMap['__string__'];

        $fn = $context->module->addFunction(
            self::ABI,
            $context->context->functionType($strPtr, false, $strPtr)
        );

        $savedBuilder = $context->builder;
        $context->builder = $context->context->builderCreate();
        $b = $context->builder;

        $entry = $fn->appendBasicBlock('addslashes_thin_entry');
        $nullIn = $fn->appendBasicBlock('addslashes_thin_null');
        $countInit = $fn->appendBasicBlock('addslashes_thin_count_init');
        $countLoop = $fn->appendBasicBlock('addslashes_thin_count_loop');
        $countBody = $fn->appendBasicBlock('addslashes_thin_count_body');
        $countEsc = $fn->appendBasicBlock('addslashes_thin_count_esc');
        $countLit = $fn->appendBasicBlock('addslashes_thin_count_lit');
        $countNext = $fn->appendBasicBlock('addslashes_thin_count_next');
        $countDone = $fn->appendBasicBlock('addslashes_thin_count_done');
        $writeLoop = $fn->appendBasicBlock('addslashes_thin_write_loop');
        $writeBody = $fn->appendBasicBlock('addslashes_thin_write_body');
        $writeNul = $fn->appendBasicBlock('addslashes_thin_write_nul');
        $writeEscCheck = $fn->appendBasicBlock('addslashes_thin_write_esc_check');
        $writeEsc = $fn->appendBasicBlock('addslashes_thin_write_esc');
        $writeLit = $fn->appendBasicBlock('addslashes_thin_write_lit');
        $writeNext = $fn->appendBasicBlock('addslashes_thin_write_next');
        $writeDone = $fn->appendBasicBlock('addslashes_thin_write_done');

        $b->positionAtEnd($entry);
        $arg = $fn->getParam(0);
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $arg, $strPtr->constNull()),
            $nullIn,
            $countInit
        );

        $b->positionAtEnd($nullIn);
        $b->returnValue($b->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $b->pointerCast($context->constantFromString(''), $i8p)
        ));

        $b->positionAtEnd($countInit);
        $inLen = $b->load($b->structGep($arg, $map['length']));
        $inChars = $b->pointerCast($b->structGep($arg, $map['value']), $i8p);
        $idx = $b->alloca($i64);
        $outLenSlot = $b->alloca($i64);
        $b->store($i64->constInt(0, false), $idx);
        $b->store($i64->constInt(0, false), $outLenSlot);
        $b->branch($countLoop);

        $b->positionAtEnd($countLoop);
        $ci = $b->load($idx);
        $b->branchIf($b->icmp(Builder::INT_ULT, $ci, $inLen), $countBody, $countDone);

        $b->positionAtEnd($countBody);
        $ch = $b->load($b->gep($inChars, $ci));
        $needsEsc = $b->or(
            $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(0, false)),
            $b->or(
                $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(\ord('\\'), false)),
                $b->or(
                    $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(\ord("'"), false)),
                    $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(\ord('"'), false))
                )
            )
        );
        $b->branchIf($needsEsc, $countEsc, $countLit);

        $b->positionAtEnd($countEsc);
        $b->store($b->add($b->load($outLenSlot), $i64->constInt(2, false)), $outLenSlot);
        $b->branch($countNext);

        $b->positionAtEnd($countLit);
        $b->store($b->add($b->load($outLenSlot), $i64->constInt(1, false)), $outLenSlot);
        $b->branch($countNext);

        $b->positionAtEnd($countNext);
        $b->store($b->add($ci, $i64->constInt(1, false)), $idx);
        $b->branch($countLoop);

        $b->positionAtEnd($countDone);
        $total = $b->load($outLenSlot);
        $result = $b->call($context->lookupFunction('__string__alloc'), $total);
        $outChars = $b->pointerCast($b->structGep($result, $map['value']), $i8p);
        $outIdx = $b->alloca($i64);
        $b->store($i64->constInt(0, false), $outIdx);
        $b->store($i64->constInt(0, false), $idx);
        $b->branch($writeLoop);

        $b->positionAtEnd($writeLoop);
        $wi = $b->load($idx);
        $b->branchIf($b->icmp(Builder::INT_ULT, $wi, $inLen), $writeBody, $writeDone);

        $b->positionAtEnd($writeBody);
        $wch = $b->load($b->gep($inChars, $wi));
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $wch, $i8->constInt(0, false)),
            $writeNul,
            $writeEscCheck
        );

        $b->positionAtEnd($writeNul);
        $oiNul = $b->load($outIdx);
        $b->store($i8->constInt(\ord('\\'), false), $b->gep($outChars, $oiNul));
        $b->store($i8->constInt(\ord('0'), false), $b->gep($outChars, $b->add($oiNul, $i64->constInt(1, false))));
        $b->store($b->add($oiNul, $i64->constInt(2, false)), $outIdx);
        $b->branch($writeNext);

        $b->positionAtEnd($writeEscCheck);
        $wNeedsEsc = $b->or(
            $b->icmp(Builder::INT_EQ, $wch, $i8->constInt(\ord('\\'), false)),
            $b->or(
                $b->icmp(Builder::INT_EQ, $wch, $i8->constInt(\ord("'"), false)),
                $b->icmp(Builder::INT_EQ, $wch, $i8->constInt(\ord('"'), false))
            )
        );
        $b->branchIf($wNeedsEsc, $writeEsc, $writeLit);

        $b->positionAtEnd($writeEsc);
        $oiEsc = $b->load($outIdx);
        $b->store($i8->constInt(\ord('\\'), false), $b->gep($outChars, $oiEsc));
        $b->store($wch, $b->gep($outChars, $b->add($oiEsc, $i64->constInt(1, false))));
        $b->store($b->add($oiEsc, $i64->constInt(2, false)), $outIdx);
        $b->branch($writeNext);

        $b->positionAtEnd($writeLit);
        $oiLit = $b->load($outIdx);
        $b->store($wch, $b->gep($outChars, $oiLit));
        $b->store($b->add($oiLit, $i64->constInt(1, false)), $outIdx);
        $b->branch($writeNext);

        $b->positionAtEnd($writeNext);
        $b->store($b->add($wi, $i64->constInt(1, false)), $idx);
        $b->branch($writeLoop);

        $b->positionAtEnd($writeDone);
        $b->returnValue($result);

        $context->builder->clearInsertionPosition();
        $context->builder = $savedBuilder;
        $context->registerFunction(self::ABI, $fn);
    }
}
