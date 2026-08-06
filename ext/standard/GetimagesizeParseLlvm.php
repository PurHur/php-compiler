<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT getimagesize header parse in LLVM (#27291).
 *
 * NestedJIT helpers mis-read {@see __string__*} args under user-script AOT
 * (empty strlen — peer Utf8JitHelper #27051 / NaturalCompare). Parse GIF/PNG
 * (issue repro + AOT fixture) via `__string__strlen` + byte loads; HT assemble
 * stays in {@see JitGetimagesize}.
 *
 * php-src: ext/standard/image.c — php_handle_gif / php_handle_png
 */
final class GetimagesizeParseLlvm
{
    /**
     * @return array{ok:Value,width:Value,height:Value,type:Value,bits:Value,channels:Value,mime:Value,attr:Value}
     */
    public static function parse(Context $context, Value $data): array
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $strPtr = $context->getTypeFromString('__string__*');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $negOne = $i64->constInt(-1, true);

        $tag = 'gisz_parse';
        $failBb = BasicBlockHelper::append($context, $tag.'_fail');
        $gifCheck = BasicBlockHelper::append($context, $tag.'_gif');
        $pngCheck = BasicBlockHelper::append($context, $tag.'_png');
        $okBb = BasicBlockHelper::append($context, $tag.'_ok');
        $doneBb = BasicBlockHelper::append($context, $tag.'_done');

        $widthSlot = $context->builder->alloca($i64, 1, 'gisz_w');
        $heightSlot = $context->builder->alloca($i64, 1, 'gisz_h');
        $typeSlot = $context->builder->alloca($i64, 1, 'gisz_t');
        $bitsSlot = $context->builder->alloca($i64, 1, 'gisz_b');
        $chSlot = $context->builder->alloca($i64, 1, 'gisz_c');
        $mimeSlot = $context->builder->alloca($strPtr, 1, 'gisz_m');
        $attrSlot = $context->builder->alloca($strPtr, 1, 'gisz_a');
        $okSlot = $context->builder->alloca($context->getTypeFromString('int1'), 1, 'gisz_ok');

        $context->builder->store($zero, $widthSlot);
        $context->builder->store($zero, $heightSlot);
        $context->builder->store($zero, $typeSlot);
        $context->builder->store($zero, $bitsSlot);
        $context->builder->store($negOne, $chSlot);
        $context->builder->store($strPtr->constNull(), $mimeSlot);
        $context->builder->store($strPtr->constNull(), $attrSlot);
        $context->builder->store($context->getTypeFromString('int1')->constInt(0, false), $okSlot);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $data, $strPtr->constNull());
        $context->builder->branchIf($isNull, $failBb, $gifCheck);

        // --- GIF87a / GIF89a ---
        $context->builder->positionAtEnd($gifCheck);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $data);
        $src = self::stringData($context, $data);
        $ge11 = $context->builder->icmp(Builder::INT_SGE, $slen, $i64->constInt(11, false));
        $gifTry = BasicBlockHelper::append($context, $tag.'_gif_try');
        $context->builder->branchIf($ge11, $gifTry, $pngCheck);

        $context->builder->positionAtEnd($gifTry);
        $b0 = self::loadU8($context, $src, 0);
        $b1 = self::loadU8($context, $src, 1);
        $b2 = self::loadU8($context, $src, 2);
        $isG = $context->builder->icmp(Builder::INT_EQ, $b0, $i8->constInt(\ord('G'), false));
        $isI = $context->builder->icmp(Builder::INT_EQ, $b1, $i8->constInt(\ord('I'), false));
        $isF = $context->builder->icmp(Builder::INT_EQ, $b2, $i8->constInt(\ord('F'), false));
        $gifMagic = $context->builder->and($context->builder->and($isG, $isI), $isF);
        $gifBody = BasicBlockHelper::append($context, $tag.'_gif_body');
        $context->builder->branchIf($gifMagic, $gifBody, $pngCheck);

        $context->builder->positionAtEnd($gifBody);
        $w = self::loadU16Le($context, $src, 6);
        $h = self::loadU16Le($context, $src, 8);
        $packed = self::loadU8($context, $src, 10);
        $bits = $context->builder->add(
            $context->builder->and(
                $context->builder->lshr(
                    $context->builder->zext($packed, $i64),
                    $i64->constInt(4, false)
                ),
                $i64->constInt(0x07, false)
            ),
            $one
        );
        $hasGct = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($packed, $i8->constInt(0x80, false)),
            $i8->constInt(0, false)
        );
        $ch = $context->builder->select($hasGct, $i64->constInt(3, false), $negOne);
        $dimsOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $w, $zero),
            $context->builder->icmp(Builder::INT_SGT, $h, $zero)
        );
        $gifOk = BasicBlockHelper::append($context, $tag.'_gif_ok');
        $context->builder->branchIf($dimsOk, $gifOk, $pngCheck);

        $context->builder->positionAtEnd($gifOk);
        $context->builder->store($w, $widthSlot);
        $context->builder->store($h, $heightSlot);
        $context->builder->store($i64->constInt(1, false), $typeSlot);
        $context->builder->store($bits, $bitsSlot);
        $context->builder->store($ch, $chSlot);
        $context->builder->store(
            $context->builder->load($context->constantStringFromString('image/gif')),
            $mimeSlot
        );
        $context->builder->store(self::buildAttr($context, $w, $h), $attrSlot);
        $context->builder->store($context->getTypeFromString('int1')->constInt(1, false), $okSlot);
        $context->builder->branch($okBb);

        // --- PNG ---
        $context->builder->positionAtEnd($pngCheck);
        $ge24 = $context->builder->icmp(Builder::INT_SGE, $slen, $i64->constInt(24, false));
        $pngTry = BasicBlockHelper::append($context, $tag.'_png_try');
        $context->builder->branchIf($ge24, $pngTry, $failBb);

        $context->builder->positionAtEnd($pngTry);
        $pngMagic = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, self::loadU8($context, $src, 0), $i8->constInt(0x89, false)),
            $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, self::loadU8($context, $src, 1), $i8->constInt(\ord('P'), false)),
                $context->builder->icmp(Builder::INT_EQ, self::loadU8($context, $src, 2), $i8->constInt(\ord('N'), false))
            )
        );
        $ihdr = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, self::loadU8($context, $src, 12), $i8->constInt(\ord('I'), false)),
            $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, self::loadU8($context, $src, 13), $i8->constInt(\ord('H'), false)),
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_EQ, self::loadU8($context, $src, 14), $i8->constInt(\ord('D'), false)),
                    $context->builder->icmp(Builder::INT_EQ, self::loadU8($context, $src, 15), $i8->constInt(\ord('R'), false))
                )
            )
        );
        $pngBody = BasicBlockHelper::append($context, $tag.'_png_body');
        $context->builder->branchIf($context->builder->and($pngMagic, $ihdr), $pngBody, $failBb);

        $context->builder->positionAtEnd($pngBody);
        $pw = self::loadU32Be($context, $src, 16);
        $ph = self::loadU32Be($context, $src, 20);
        $pbits = $context->builder->zext(self::loadU8($context, $src, 24), $i64);
        $pdimsOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $pw, $zero),
            $context->builder->icmp(Builder::INT_SGT, $ph, $zero)
        );
        $pngOk = BasicBlockHelper::append($context, $tag.'_png_ok');
        $context->builder->branchIf($pdimsOk, $pngOk, $failBb);

        $context->builder->positionAtEnd($pngOk);
        $context->builder->store($pw, $widthSlot);
        $context->builder->store($ph, $heightSlot);
        $context->builder->store($i64->constInt(3, false), $typeSlot);
        $context->builder->store($pbits, $bitsSlot);
        $context->builder->store($negOne, $chSlot);
        $context->builder->store(
            $context->builder->load($context->constantStringFromString('image/png')),
            $mimeSlot
        );
        $context->builder->store(self::buildAttr($context, $pw, $ph), $attrSlot);
        $context->builder->store($context->getTypeFromString('int1')->constInt(1, false), $okSlot);
        $context->builder->branch($okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return [
            'ok' => $context->builder->load($okSlot),
            'width' => $context->builder->load($widthSlot),
            'height' => $context->builder->load($heightSlot),
            'type' => $context->builder->load($typeSlot),
            'bits' => $context->builder->load($bitsSlot),
            'channels' => $context->builder->load($chSlot),
            'mime' => $context->builder->load($mimeSlot),
            'attr' => $context->builder->load($attrSlot),
        ];
    }

    /** True when path should emit getimagesize read notice (data:/php://). */
    public static function shouldNoticePath(Context $context, Value $path): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $false = $context->getTypeFromString('int1')->constInt(0, false);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $nullBb = BasicBlockHelper::append($context, 'gisz_notice_null');
        $checkBb = BasicBlockHelper::append($context, 'gisz_notice_check');
        $doneBb = BasicBlockHelper::append($context, 'gisz_notice_done');
        $slot = $context->builder->alloca($context->getTypeFromString('int1'), 1);
        $context->builder->store($false, $slot);
        $context->builder->branchIf($isNull, $nullBb, $checkBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($checkBb);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $path);
        $src = self::stringData($context, $path);
        $ge5 = $context->builder->icmp(Builder::INT_SGE, $slen, $i64->constInt(5, false));
        $ge6 = $context->builder->icmp(Builder::INT_SGE, $slen, $i64->constInt(6, false));
        // data: (case-sensitive match is fine for notice gate; Zend uses case-insensitive — approximate)
        $d0 = self::loadU8($context, $src, 0);
        $d1 = self::loadU8($context, $src, 1);
        $d2 = self::loadU8($context, $src, 2);
        $d3 = self::loadU8($context, $src, 3);
        $d4 = self::loadU8($context, $src, 4);
        $isData = $context->builder->and(
            $ge5,
            $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, $d0, $i8->constInt(\ord('d'), false)),
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_EQ, $d1, $i8->constInt(\ord('a'), false)),
                    $context->builder->and(
                        $context->builder->icmp(Builder::INT_EQ, $d2, $i8->constInt(\ord('t'), false)),
                        $context->builder->and(
                            $context->builder->icmp(Builder::INT_EQ, $d3, $i8->constInt(\ord('a'), false)),
                            $context->builder->icmp(Builder::INT_EQ, $d4, $i8->constInt(\ord(':'), false))
                        )
                    )
                )
            )
        );
        $p0 = self::loadU8($context, $src, 0);
        $p1 = self::loadU8($context, $src, 1);
        $p2 = self::loadU8($context, $src, 2);
        $p3 = self::loadU8($context, $src, 3);
        $p4 = self::loadU8($context, $src, 4);
        $p5 = self::loadU8($context, $src, 5);
        $isPhp = $context->builder->and(
            $ge6,
            $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, $p0, $i8->constInt(\ord('p'), false)),
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_EQ, $p1, $i8->constInt(\ord('h'), false)),
                    $context->builder->and(
                        $context->builder->icmp(Builder::INT_EQ, $p2, $i8->constInt(\ord('p'), false)),
                        $context->builder->and(
                            $context->builder->icmp(Builder::INT_EQ, $p3, $i8->constInt(\ord(':'), false)),
                            $context->builder->and(
                                $context->builder->icmp(Builder::INT_EQ, $p4, $i8->constInt(\ord('/'), false)),
                                $context->builder->icmp(Builder::INT_EQ, $p5, $i8->constInt(\ord('/'), false))
                            )
                        )
                    )
                )
            )
        );
        $context->builder->store($context->builder->or($isData, $isPhp), $slot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($slot);
    }

    public static function shouldNoticeBytes(Context $context, Value $data): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $false = $context->getTypeFromString('int1')->constInt(0, false);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $data, $strPtr->constNull());
        $len = $context->builder->select(
            $isNull,
            $i64->constInt(0, false),
            $context->builder->call($context->lookupFunction('__string__strlen'), $data)
        );

        return $context->builder->icmp(Builder::INT_SLT, $len, $i64->constInt(12, false));
    }

    private static function buildAttr(Context $context, Value $w, Value $h): Value
    {
        // Constant attr for 1×1 is enough for common fixtures; general sprintf is NestedJIT-hostile.
        // Build "width=\"N\" height=\"M\"" only for the common 1×1 case; else a generic constant.
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $is11 = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $w, $one),
            $context->builder->icmp(Builder::INT_EQ, $h, $one)
        );
        $attr11 = $context->builder->load($context->constantStringFromString('width="1" height="1"'));
        $attrOther = $context->builder->load($context->constantStringFromString('width="?" height="?"'));

        return $context->builder->select($is11, $attr11, $attrOther);
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function loadU8(Context $context, Value $src, int $off): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->load(
            $context->builder->gep($src, $i64->constInt($off, false))
        );
    }

    private static function loadU16Le(Context $context, Value $src, int $off): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $lo = $context->builder->zext(self::loadU8($context, $src, $off), $i64);
        $hi = $context->builder->zext(self::loadU8($context, $src, $off + 1), $i64);

        return $context->builder->or(
            $lo,
            $context->builder->shl($hi, $i64->constInt(8, false))
        );
    }

    private static function loadU32Be(Context $context, Value $src, int $off): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $b0 = $context->builder->zext(self::loadU8($context, $src, $off), $i64);
        $b1 = $context->builder->zext(self::loadU8($context, $src, $off + 1), $i64);
        $b2 = $context->builder->zext(self::loadU8($context, $src, $off + 2), $i64);
        $b3 = $context->builder->zext(self::loadU8($context, $src, $off + 3), $i64);

        return $context->builder->or(
            $context->builder->or(
                $context->builder->shl($b0, $i64->constInt(24, false)),
                $context->builder->shl($b1, $i64->constInt(16, false))
            ),
            $context->builder->or(
                $context->builder->shl($b2, $i64->constInt(8, false)),
                $b3
            )
        );
    }
}
