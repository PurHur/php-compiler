<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\ext\standard\strval;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT fputcsv() field formatting via NestedJIT-safe per-cell helper (#12447, #27180).
 *
 * Thin AOT: walk fields HashTable in LLVM (peer {@see \PHPCompiler\ext\standard\JitImplode}),
 * coerce with strval(), format each cell via {@see \PHPCompiler\ext\standard\CsvFputcsvJitHelper}
 * (no HashTable::iterate / VmFputcsv / VmCsv under NestedJIT).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmCsv::formatLine}
 * php-src: ext/standard/file.c — php_fputcsv()
 */
final class FputcsvRuntime
{
    private const HELPER_PATH = '/ext/standard/CsvFputcsvJitHelper.php';

    private const FORMAT_FIELD_HELPER = 'PHPCompiler\\ext\\standard\\CsvFputcsvJitHelper::formatFieldArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FORMAT_FIELD_HELPER,
    ];

    private static int $seq = 0;

    public static function formatFields(
        Context $context,
        Value $fieldsHt,
        Value $separator,
        Value $enclosure,
        Value $escape,
    ): Value {
        self::ensureLinked($context);

        $tag = 'fp'.(string) ++self::$seq;
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $zeroI64 = $i64->constInt(0, false);

        $resultSlot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $startedSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($i1->constInt(0, false), $startedSlot);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zeroI64);
        $context->builder->store($emptyStr, $resultSlot);

        $strval = new strval();
        self::appendPackedValues(
            $context,
            $fieldsHt,
            $separator,
            $enclosure,
            $escape,
            $resultSlot,
            $startedSlot,
            $strval,
            $tag
        );
        self::appendStringKeyValues(
            $context,
            $fieldsHt,
            $separator,
            $enclosure,
            $escape,
            $resultSlot,
            $startedSlot,
            $strval,
            $tag
        );

        $result = $context->builder->load($resultSlot);
        BasicBlockHelper::branchToFreshContinue($context, 'fputcsv_format_continue_'.$tag);

        return $result;
    }

    public static function ensureLinked(Context $context): void
    {
        if (!self::compiledHelpersMissing($context)) {
            return;
        }

        $savedBlock = self::saveInsertBlock($context);
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            [self::HELPER_PATH],
            self::COMPILED_HELPERS,
            '#27180',
            true // skip helper-runtime cache — force NestedJIT (#27069 peer)
        );
        self::restoreInsertBlock($context, $savedBlock);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function appendPackedValues(
        Context $context,
        Value $haystack,
        Value $separator,
        Value $enclosure,
        Value $escape,
        Value $resultSlot,
        Value $startedSlot,
        strval $strval,
        string $tag
    ): void {
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load($context->builder->structGep($haystack, $htMap['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'fputcsv_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'fputcsv_pk_body_'.$tag);
        $take = BasicBlockHelper::append($context, 'fputcsv_pk_take_'.$tag);
        $next = BasicBlockHelper::append($context, 'fputcsv_pk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'fputcsv_pk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $haystack,
            $idx
        );
        $context->builder->branchIf($isSet, $take, $next);

        $context->builder->positionAtEnd($take);
        $partBox = HashTableHelper::readIndexedToValueBox($context, $haystack, $idx);
        self::appendFormattedPart(
            $context,
            $separator,
            $enclosure,
            $escape,
            $resultSlot,
            $startedSlot,
            $strval,
            $partBox
        );
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function appendStringKeyValues(
        Context $context,
        Value $haystack,
        Value $separator,
        Value $enclosure,
        Value $escape,
        Value $resultSlot,
        Value $startedSlot,
        strval $strval,
        string $tag
    ): void {
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($haystack, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $head = BasicBlockHelper::append($context, 'fputcsv_sk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'fputcsv_sk_body_'.$tag);
        $next = BasicBlockHelper::append($context, 'fputcsv_sk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'fputcsv_sk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $valSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $valSlot, $valField);
        $partBox = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valSlot);
        self::appendFormattedPart(
            $context,
            $separator,
            $enclosure,
            $escape,
            $resultSlot,
            $startedSlot,
            $strval,
            $partBox
        );

        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function appendFormattedPart(
        Context $context,
        Value $separator,
        Value $enclosure,
        Value $escape,
        Value $resultSlot,
        Value $startedSlot,
        strval $strval,
        Variable $partBox
    ): void {
        $tag = 'ap'.(string) ++self::$seq;
        $i1 = $context->getTypeFromString('int1');
        $cell = $strval->valueToString($context, JitValueBox::pointer($context, $partBox->value));
        $cellSep = $context->builder->call($context->lookupFunction('__string__separate'), $cell);
        $sepSep = $context->builder->call($context->lookupFunction('__string__separate'), $separator);
        $encSep = $context->builder->call($context->lookupFunction('__string__separate'), $enclosure);
        $escSep = $context->builder->call($context->lookupFunction('__string__separate'), $escape);

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context),
            [$cellSep, $sepSep, $encSep, $escSep]
        );
        $part = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);

        $firstBb = BasicBlockHelper::append($context, 'fputcsv_first_'.$tag);
        $restBb = BasicBlockHelper::append($context, 'fputcsv_rest_'.$tag);
        $done = BasicBlockHelper::append($context, 'fputcsv_part_done_'.$tag);

        $started = $context->builder->load($startedSlot);
        $context->builder->branchIf($started, $restBb, $firstBb);

        $context->builder->positionAtEnd($firstBb);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $part);
        $context->builder->store($owned, $resultSlot);
        $context->builder->store($i1->constInt(1, false), $startedSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($restBb);
        $acc = $context->builder->load($resultSlot);
        $withGlue = JitStringConcat::concat($context, $acc, $separator);
        $acc = JitStringConcat::concat($context, $withGlue, $part);
        $context->builder->store($acc, $resultSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureLinked($context);

        return JitVmHelperLink::lookupCompiled($context, self::FORMAT_FIELD_HELPER, '#27180');
    }

    private static function compiledHelpersMissing(Context $context): bool
    {
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                return true;
            }
        }

        return false;
    }

    private static function saveInsertBlock(Context $context): mixed
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, mixed $savedBlock): void
    {
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
