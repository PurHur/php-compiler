<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Native thin-AOT str_replace / str_ireplace — no NestedJIT helper (#36388).
 *
 * Stale helper-runtime TUs still define leaky {@code phpc_str_replace} (NestedJIT
 * concat/slice scratch). Call sites use {@code phpc_str_replace_r1} so this body
 * always wins — peer {@see NumberFormatRuntime} / {@code __compiler_number_format_r1}.
 *
 * Always returns a freshly allocated {@code __string__*} (empty needle and miss
 * copy the subject) so FUNCCALL temps free on unset.
 *
 * php-src: ext/standard/string.c — php_str_to_str_ex / php_str_replace
 */
final class StrReplaceRuntime
{
    public const ABI_REPLACE = 'phpc_str_replace_r1';

    public const ABI_IREPLACE = 'phpc_str_ireplace_r1';

    public const ABI_TAKE_COUNT = 'phpc_str_replace_take_count_r1';

    public const BRIDGE_REPLACE = 'str_replace_r1_bridge_entry';

    public const BRIDGE_IREPLACE = 'str_ireplace_r1_bridge_entry';

    public const BRIDGE_TAKE_COUNT = 'str_replace_take_count_r1_bridge_entry';

    private const G_LAST_COUNT = 'phpc_str_replace_r1_last_count';

    private static int $seq = 0;

    /**
     * Emit full replace bridge body (builder already at entry). Ends with returnValue.
     */
    public static function emitBridgeBody(
        Context $context,
        LlvmFunction $fn,
        bool $caseInsensitive
    ): void {
        self::ensureDecls($context);
        ++self::$seq;
        $tag = ($caseInsensitive ? 'sri_' : 'sr_').(string) self::$seq;

        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $search = $fn->getParam(0);
        $replace = $fn->getParam(1);
        $subject = $fn->getParam(2);

        $searchLen = $context->builder->load($context->builder->structGep($search, $map['length']));
        $replaceLen = $context->builder->load($context->builder->structGep($replace, $map['length']));
        $subjectLen = $context->builder->load($context->builder->structGep($subject, $map['length']));
        $searchData = $context->builder->structGep($search, $map['value']);
        $replaceData = $context->builder->structGep($replace, $map['value']);
        $subjectData = $context->builder->structGep($subject, $map['value']);

        $scanPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $countPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $srcPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $dstPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $emitPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zero, $scanPtr);
        $context->builder->store($zero, $countPtr);

        $emptyBb = $fn->appendBasicBlock($tag.'_empty');
        $cntHead = $fn->appendBasicBlock($tag.'_cnt_h');
        $cntBody = $fn->appendBasicBlock($tag.'_cnt_b');
        $cntHit = $fn->appendBasicBlock($tag.'_cnt_hit');
        $cntNext = $fn->appendBasicBlock($tag.'_cnt_next');
        $cntDone = $fn->appendBasicBlock($tag.'_cnt_d');
        $copyBb = $fn->appendBasicBlock($tag.'_copy');
        $allocBb = $fn->appendBasicBlock($tag.'_alloc');

        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $searchLen, $zero),
            $emptyBb,
            $cntHead
        );

        $context->builder->positionAtEnd($emptyBb);
        self::storeLastCount($context, $zero);
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__separate'), $subject)
        );

        // Pass 1 — count matches (sliding window).
        $context->builder->positionAtEnd($cntHead);
        $scan = $context->builder->load($scanPtr);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_SGE,
                $context->builder->sub($subjectLen, $scan),
                $searchLen
            ),
            $cntBody,
            $cntDone
        );

        $context->builder->positionAtEnd($cntBody);
        $eq = self::bytesEqual(
            $context,
            $fn,
            $context->builder->gep($subjectData, $scan),
            $searchData,
            $searchLen,
            $caseInsensitive,
            $tag.'_ceq'
        );
        $context->builder->branchIf($eq, $cntHit, $cntNext);

        $context->builder->positionAtEnd($cntHit);
        $context->builder->store(
            $context->builder->add($context->builder->load($countPtr), $one),
            $countPtr
        );
        $context->builder->store($context->builder->add($scan, $searchLen), $scanPtr);
        $context->builder->branch($cntHead);

        $context->builder->positionAtEnd($cntNext);
        $context->builder->store($context->builder->add($scan, $one), $scanPtr);
        $context->builder->branch($cntHead);

        $context->builder->positionAtEnd($cntDone);
        $matchCount = $context->builder->load($countPtr);
        self::storeLastCount($context, $matchCount);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_UGT, $matchCount, $zero),
            $allocBb,
            $copyBb
        );

        $context->builder->positionAtEnd($copyBb);
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__separate'), $subject)
        );

        // Pass 2 — allocate + copy prefixes / replacements / tail.
        $context->builder->positionAtEnd($allocBb);
        $outLen = $context->builder->add(
            $subjectLen,
            $context->builder->mul($matchCount, $context->builder->sub($replaceLen, $searchLen))
        );
        $buf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $context->builder->add($outLen, $sizeT->constInt(1, false))
        );
        $out = $context->builder->pointerCast($buf, $i8p);
        $context->builder->store($zero, $srcPtr);
        $context->builder->store($zero, $dstPtr);
        $context->builder->store($zero, $emitPtr);

        $fillHead = $fn->appendBasicBlock($tag.'_fh');
        $fillBody = $fn->appendBasicBlock($tag.'_fb');
        $fillHit = $fn->appendBasicBlock($tag.'_fhit');
        $fillNext = $fn->appendBasicBlock($tag.'_fnext');
        $fillTail = $fn->appendBasicBlock($tag.'_ftail');
        $fillDone = $fn->appendBasicBlock($tag.'_fdone');
        $context->builder->branch($fillHead);

        $context->builder->positionAtEnd($fillHead);
        $src = $context->builder->load($srcPtr);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_SGE,
                $context->builder->sub($subjectLen, $src),
                $searchLen
            ),
            $fillBody,
            $fillTail
        );

        $context->builder->positionAtEnd($fillBody);
        $eq2 = self::bytesEqual(
            $context,
            $fn,
            $context->builder->gep($subjectData, $src),
            $searchData,
            $searchLen,
            $caseInsensitive,
            $tag.'_feq'
        );
        $context->builder->branchIf($eq2, $fillHit, $fillNext);

        $context->builder->positionAtEnd($fillHit);
        $emit = $context->builder->load($emitPtr);
        $prefix = $context->builder->sub($src, $emit);
        $dst = $context->builder->load($dstPtr);
        self::memcpyBytes($context, $out, $dst, $subjectData, $emit, $prefix);
        $dstAfterPrefix = $context->builder->add($dst, $prefix);
        self::memcpyBytes($context, $out, $dstAfterPrefix, $replaceData, $zero, $replaceLen);
        $context->builder->store(
            $context->builder->add($dstAfterPrefix, $replaceLen),
            $dstPtr
        );
        $nextSrc = $context->builder->add($src, $searchLen);
        $context->builder->store($nextSrc, $srcPtr);
        $context->builder->store($nextSrc, $emitPtr);
        $context->builder->branch($fillHead);

        $context->builder->positionAtEnd($fillNext);
        $context->builder->store($context->builder->add($src, $one), $srcPtr);
        $context->builder->branch($fillHead);

        $context->builder->positionAtEnd($fillTail);
        $emit2 = $context->builder->load($emitPtr);
        $tail = $context->builder->sub($subjectLen, $emit2);
        self::memcpyBytes(
            $context,
            $out,
            $context->builder->load($dstPtr),
            $subjectData,
            $emit2,
            $tail
        );
        $context->builder->branch($fillDone);

        $context->builder->positionAtEnd($fillDone);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $outLen,
            $out
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);
        $context->builder->returnValue($result);
    }

    public static function emitTakeCountBody(Context $context, LlvmFunction $fn): void
    {
        self::ensureLastCountGlobal($context);
        $i64 = $context->getTypeFromString('int64');
        $g = $context->module->getNamedGlobal(self::G_LAST_COUNT);
        $val = $context->builder->load($g);
        $context->builder->store($i64->constInt(0, false), $g);
        $context->builder->returnValue($val);
    }

    private static function memcpyBytes(
        Context $context,
        Value $dstBase,
        Value $dstOff,
        Value $srcBase,
        Value $srcOff,
        Value $len
    ): void {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $dst = $context->builder->gep($dstBase, $dstOff);
        $src = $context->builder->gep($srcBase, $srcOff);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->pointerCast($dst, $i8p),
            $context->builder->pointerCast($src, $i8p),
            $context->builder->zExt($len, $sizeT)
        );
    }

    private static function bytesEqual(
        Context $context,
        LlvmFunction $fn,
        Value $a,
        Value $b,
        Value $len,
        bool $caseInsensitive,
        string $tag
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        if (!$caseInsensitive) {
            $cmp = $context->builder->call(
                $context->lookupFunction('memcmp'),
                $a,
                $b,
                $context->builder->zExt($len, $sizeT)
            );

            return $context->builder->icmp(
                Builder::INT_EQ,
                $cmp,
                $cmp->typeOf()->constInt(0, false)
            );
        }

        $idxPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $okPtr = BasicBlockHelper::entryAlloca($context, $i8);
        $context->builder->store($zero, $idxPtr);
        $context->builder->store($i8->constInt(1, false), $okPtr);

        $head = $fn->appendBasicBlock($tag.'_ci_h');
        $body = $fn->appendBasicBlock($tag.'_ci_b');
        $done = $fn->appendBasicBlock($tag.'_ci_d');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($idxPtr);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULT, $i, $len),
            $body,
            $done
        );

        $context->builder->positionAtEnd($body);
        $ca = $context->builder->load($context->builder->gep($a, $i));
        $cb = $context->builder->load($context->builder->gep($b, $i));
        $la = self::asciiLower($context, $ca);
        $lb = self::asciiLower($context, $cb);
        $same = $context->builder->icmp(Builder::INT_EQ, $la, $lb);
        $context->builder->store(
            $context->builder->select($same, $i8->constInt(1, false), $i8->constInt(0, false)),
            $okPtr
        );
        $context->builder->store($context->builder->add($i, $one), $idxPtr);
        $cont = $fn->appendBasicBlock($tag.'_ci_cont');
        $context->builder->branchIf($same, $cont, $done);
        $context->builder->positionAtEnd($cont);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($okPtr),
            $i8->constInt(0, false)
        );
    }

    private static function asciiLower(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $geA = $context->builder->icmp(Builder::INT_UGE, $ch, $i8->constInt(65, false));
        $leZ = $context->builder->icmp(Builder::INT_ULE, $ch, $i8->constInt(90, false));
        $isUpper = $context->builder->and($geA, $leZ);

        return $context->builder->select(
            $isUpper,
            $context->builder->add($ch, $i8->constInt(32, false)),
            $ch
        );
    }

    private static function storeLastCount(Context $context, Value $count): void
    {
        self::ensureLastCountGlobal($context);
        $context->builder->store($count, $context->module->getNamedGlobal(self::G_LAST_COUNT));
    }

    private static function ensureLastCountGlobal(Context $context): void
    {
        if (null !== $context->module->getNamedGlobal(self::G_LAST_COUNT)) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $g = $context->module->addGlobal($i64, self::G_LAST_COUNT);
        $g->setInitializer($i64->constInt(0, false));
    }

    private static function ensureDecls(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');

        LibcExtern::ensureMemcmpDecl($context);
        LibcExtern::ensureMemcpyImplemented($context);
        self::ensureLastCountGlobal($context);

        foreach (
            [
                '__mm__malloc' => [$i8p, false, [$sizeT]],
                '__mm__free' => [$voidTy, false, [$i8p]],
                '__string__init' => [$strPtr, false, [$i64, $i8p]],
                '__string__separate' => [$strPtr, false, [$strPtr]],
            ] as $name => [$ret, $vararg, $params]
        ) {
            try {
                $context->lookupFunction($name);
                continue;
            } catch (\Throwable) {
            }
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, $vararg, ...$params)
                );
            }
            $context->registerFunction($name, $fn);
        }
    }
}
