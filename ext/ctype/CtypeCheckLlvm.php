<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ctype;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM for ctype_* string/int checks (php-src ext/ctype/ctype.c).
 *
 * Avoids the NestedJIT {@see CtypeJitHelper} ABI trap: unit.bc declares
 * {@code __string__*} while the compiled body reads a {@code __value__*} type
 * tag, so {@code ctype_digit(string \$s)} always returned false (#36386).
 * Predicates mirror {@see VmCtype::checkByte} / {@see VmCtype::checkInt}.
 */
final class CtypeCheckLlvm
{
    private static int $seq = 0;

    /** Locale-independent ASCII check over every byte; empty → false. */
    public static function checkString(Context $context, Value $strPtr, int $kind): Value
    {
        $tag = 'ccs'.(string) ++self::$seq;
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $preBlock = $context->builder->getInsertBlock();
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $chars = $context->builder->structGep($strPtr, $map['value']);

        $emptyBlock = BasicBlockHelper::append($context, 'ctype_str_empty_'.$tag);
        $loopHeader = BasicBlockHelper::append($context, 'ctype_str_hdr_'.$tag);
        $loopBody = BasicBlockHelper::append($context, 'ctype_str_body_'.$tag);
        $failBlock = BasicBlockHelper::append($context, 'ctype_str_fail_'.$tag);
        $okBlock = BasicBlockHelper::append($context, 'ctype_str_ok_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'ctype_str_done_'.$tag);

        $context->builder->positionAtEnd($preBlock);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $len, $zero),
            $emptyBlock,
            $loopHeader
        );

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($loopHeader);
        $idx = $context->builder->phi($i64, 'ctype_idx_'.$tag);
        $idx->addIncoming($zero, $preBlock);
        $still = $context->builder->icmp(Builder::INT_SLT, $idx, $len);
        $context->builder->branchIf($still, $loopBody, $okBlock);

        $context->builder->positionAtEnd($loopBody);
        $bytePtr = $context->builder->gep($chars, $idx);
        $byte = $context->builder->zext($context->builder->load($bytePtr), $i64);
        $okByte = self::emitBytePredicate($context, $byte, $kind);
        $nextIdx = $context->builder->add($idx, $one);
        $idx->addIncoming($nextIdx, $context->builder->getInsertBlock());
        $context->builder->branchIf($okByte, $loopHeader, $failBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i1, 'ctype_str_res_'.$tag);
        $phi->addIncoming($i1->constInt(0, false), $emptyBlock);
        $phi->addIncoming($i1->constInt(0, false), $failBlock);
        $phi->addIncoming($i1->constInt(1, false), $okBlock);

        return $phi;
    }

    /** php-src ctype_fallback int path — {@see VmCtype::checkInt}. */
    public static function checkInt(
        Context $context,
        Value $longVal,
        int $kind,
        bool $allowDigits,
        bool $allowMinus
    ): Value {
        $tag = 'cci'.(string) ++self::$seq;
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');

        $pre = $context->builder->getInsertBlock();
        $asByteBlock = BasicBlockHelper::append($context, 'ctype_int_asbyte_'.$tag);
        $posBlock = BasicBlockHelper::append($context, 'ctype_int_pos_'.$tag);
        $negBlock = BasicBlockHelper::append($context, 'ctype_int_neg_'.$tag);
        $done = BasicBlockHelper::append($context, 'ctype_int_done_'.$tag);

        $inU8 = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $longVal, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SLE, $longVal, $i64->constInt(255, false))
        );
        $inI8 = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $longVal, $i64->constInt(-128, false)),
            $context->builder->icmp(Builder::INT_SLT, $longVal, $i64->constInt(0, false))
        );

        $context->builder->positionAtEnd($pre);
        $context->builder->branchIf(
            $context->builder->or($inU8, $inI8),
            $asByteBlock,
            $posBlock
        );

        $context->builder->positionAtEnd($asByteBlock);
        $raw = $context->builder->select(
            $inU8,
            $longVal,
            $context->builder->add($longVal, $i64->constInt(256, false))
        );
        $byteOk = self::emitBytePredicate($context, $raw, $kind);
        $asByteEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($posBlock);
        $isNonNeg = $context->builder->icmp(Builder::INT_SGE, $longVal, $i64->constInt(0, false));
        $allowPos = BasicBlockHelper::append($context, 'ctype_int_allowpos_'.$tag);
        $context->builder->branchIf($isNonNeg, $allowPos, $negBlock);

        $context->builder->positionAtEnd($allowPos);
        $posResult = $i1->constInt($allowDigits ? 1 : 0, false);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($negBlock);
        $negResult = $i1->constInt($allowMinus ? 1 : 0, false);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i1, 'ctype_int_res_'.$tag);
        $phi->addIncoming($byteOk, $asByteEnd);
        $phi->addIncoming($posResult, $allowPos);
        $phi->addIncoming($negResult, $negBlock);

        return $phi;
    }

    private static function emitBytePredicate(Context $context, Value $byteI64, int $kind): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $masked = $context->builder->and($byteI64, $i64->constInt(0xff, false));

        return match ($kind) {
            VmCtype::KIND_DIGIT => self::inRange($context, $masked, 48, 57),
            VmCtype::KIND_LOWER => self::inRange($context, $masked, 97, 122),
            VmCtype::KIND_UPPER => self::inRange($context, $masked, 65, 90),
            VmCtype::KIND_ALPHA => $context->builder->or(
                self::inRange($context, $masked, 65, 90),
                self::inRange($context, $masked, 97, 122)
            ),
            VmCtype::KIND_ALNUM => $context->builder->or(
                self::inRange($context, $masked, 48, 57),
                $context->builder->or(
                    self::inRange($context, $masked, 65, 90),
                    self::inRange($context, $masked, 97, 122)
                )
            ),
            VmCtype::KIND_XDIGIT => $context->builder->or(
                self::inRange($context, $masked, 48, 57),
                $context->builder->or(
                    self::inRange($context, $masked, 65, 70),
                    self::inRange($context, $masked, 97, 102)
                )
            ),
            VmCtype::KIND_CNTRL => $context->builder->or(
                $context->builder->icmp(Builder::INT_SLT, $masked, $i64->constInt(32, false)),
                $context->builder->icmp(Builder::INT_EQ, $masked, $i64->constInt(127, false))
            ),
            VmCtype::KIND_SPACE => self::isSpace($context, $masked),
            VmCtype::KIND_PRINT => self::inRange($context, $masked, 32, 126),
            VmCtype::KIND_GRAPH => $context->builder->and(
                self::inRange($context, $masked, 32, 126),
                $context->builder->not(self::isSpace($context, $masked))
            ),
            VmCtype::KIND_PUNCT => $context->builder->and(
                self::inRange($context, $masked, 32, 126),
                $context->builder->and(
                    $context->builder->not(self::isSpace($context, $masked)),
                    $context->builder->not(
                        $context->builder->or(
                            self::inRange($context, $masked, 48, 57),
                            $context->builder->or(
                                self::inRange($context, $masked, 65, 90),
                                self::inRange($context, $masked, 97, 122)
                            )
                        )
                    )
                )
            ),
            default => throw new \LogicException('Unknown ctype kind: '.$kind),
        };
    }

    private static function inRange(Context $context, Value $byte, int $lo, int $hi): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $byte, $i64->constInt($lo, false)),
            $context->builder->icmp(Builder::INT_SLE, $byte, $i64->constInt($hi, false))
        );
    }

    private static function isSpace(Context $context, Value $byte): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $eq = static fn (int $c): Value => $context->builder->icmp(
            Builder::INT_EQ,
            $byte,
            $i64->constInt($c, false)
        );

        return $context->builder->or(
            $eq(9),
            $context->builder->or(
                $eq(10),
                $context->builder->or(
                    $eq(11),
                    $context->builder->or(
                        $eq(12),
                        $context->builder->or($eq(13), $eq(32))
                    )
                )
            )
        );
    }
}
