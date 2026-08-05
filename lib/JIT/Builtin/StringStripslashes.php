<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for stripslashes() (#14742, #18792, #26907).
 *
 * Embed/JIT: NestedJIT {@see \PHPCompiler\ext\standard\StripslashesJitHelper}.
 * Thin standalone AOT: pure LLVM (NestedJIT of VmString::stripslashes segfaults — #26907).
 * SSOT: {@see \PHPCompiler\ext\standard\VmString::stripslashes()}.
 * php-src: ext/standard/stripslashes.c — PHP_FUNCTION(stripslashes)
 */
final class StringStripslashes
{
    private const ABI = '__string__stripslashes';

    private const HELPER_PATH = '/ext/standard/StripslashesJitHelper.php';

    private const STRIPSLASHES_HELPER = 'PHPCompiler\\ext\\standard\\StripslashesJitHelper::stripslashesArgv';

    private const BRIDGE_ENTRY = 'stripslashes_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRIPSLASHES_HELPER,
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
            self::STRIPSLASHES_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18792'
        );
    }

    /**
     * Thin AOT: drop backslash escapes; \0 → NUL. Output length ≤ input (#26907).
     * Matches {@see \PHPCompiler\ext\standard\VmString::stripslashes()}.
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

        $entry = $fn->appendBasicBlock('stripslashes_thin_entry');
        $nullIn = $fn->appendBasicBlock('stripslashes_thin_null');
        $init = $fn->appendBasicBlock('stripslashes_thin_init');
        $loop = $fn->appendBasicBlock('stripslashes_thin_loop');
        $body = $fn->appendBasicBlock('stripslashes_thin_body');
        $bs = $fn->appendBasicBlock('stripslashes_thin_bs');
        $bsZero = $fn->appendBasicBlock('stripslashes_thin_bs_zero');
        $bsOther = $fn->appendBasicBlock('stripslashes_thin_bs_other');
        $lit = $fn->appendBasicBlock('stripslashes_thin_lit');
        $next = $fn->appendBasicBlock('stripslashes_thin_next');
        $done = $fn->appendBasicBlock('stripslashes_thin_done');

        $b->positionAtEnd($entry);
        $arg = $fn->getParam(0);
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $arg, $strPtr->constNull()),
            $nullIn,
            $init
        );

        $b->positionAtEnd($nullIn);
        $b->returnValue($b->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $b->pointerCast($context->constantFromString(''), $i8p)
        ));

        $b->positionAtEnd($init);
        $inLen = $b->load($b->structGep($arg, $map['length']));
        $inChars = $b->pointerCast($b->structGep($arg, $map['value']), $i8p);
        // stripslashes never grows — allocate input length, then set final length.
        $result = $b->call($context->lookupFunction('__string__alloc'), $inLen);
        $outChars = $b->pointerCast($b->structGep($result, $map['value']), $i8p);
        $inIdx = $b->alloca($i64);
        $outIdx = $b->alloca($i64);
        $b->store($i64->constInt(0, false), $inIdx);
        $b->store($i64->constInt(0, false), $outIdx);
        $b->branch($loop);

        $b->positionAtEnd($loop);
        $i = $b->load($inIdx);
        $b->branchIf($b->icmp(Builder::INT_ULT, $i, $inLen), $body, $done);

        $b->positionAtEnd($body);
        $ch = $b->load($b->gep($inChars, $i));
        $hasNext = $b->icmp(Builder::INT_ULT, $b->add($i, $i64->constInt(1, false)), $inLen);
        $isBs = $b->and(
            $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(\ord('\\'), false)),
            $hasNext
        );
        $b->branchIf($isBs, $bs, $lit);

        $b->positionAtEnd($bs);
        $nextCh = $b->load($b->gep($inChars, $b->add($i, $i64->constInt(1, false))));
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $nextCh, $i8->constInt(\ord('0'), false)),
            $bsZero,
            $bsOther
        );

        $b->positionAtEnd($bsZero);
        $oiZ = $b->load($outIdx);
        $b->store($i8->constInt(0, false), $b->gep($outChars, $oiZ));
        $b->store($b->add($oiZ, $i64->constInt(1, false)), $outIdx);
        $b->store($b->add($i, $i64->constInt(2, false)), $inIdx);
        $b->branch($next);

        $b->positionAtEnd($bsOther);
        $oiO = $b->load($outIdx);
        $b->store($nextCh, $b->gep($outChars, $oiO));
        $b->store($b->add($oiO, $i64->constInt(1, false)), $outIdx);
        $b->store($b->add($i, $i64->constInt(2, false)), $inIdx);
        $b->branch($next);

        $b->positionAtEnd($lit);
        $oiL = $b->load($outIdx);
        $b->store($ch, $b->gep($outChars, $oiL));
        $b->store($b->add($oiL, $i64->constInt(1, false)), $outIdx);
        $b->store($b->add($i, $i64->constInt(1, false)), $inIdx);
        $b->branch($next);

        $b->positionAtEnd($next);
        $b->branch($loop);

        $b->positionAtEnd($done);
        $finalLen = $b->load($outIdx);
        $b->store($finalLen, $b->structGep($result, $map['length']));
        $b->returnValue($result);

        $context->builder->clearInsertionPosition();
        $context->builder = $savedBuilder;
        $context->registerFunction(self::ABI, $fn);
    }
}
