<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for header() — writes a response header line to stdout (CGI-style).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitHeader
{
    /**
     * Emit only transport lines (HTTP status line, Location) so body headers stay in
     * {@see \PHPCompiler\JIT\Builtin\ResponseHeaders} until header_list() (issue #311).
     */
    public static function emitTransport(Context $context, Value $strPtr): void
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($strPtr, $map['length']));
        $data = $context->builder->structGep($strPtr, $map['value']);
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $lenAtLeast = static function (Value $n) use ($context, $len, $sizeT): Value {
            return $context->builder->icmp(Builder::INT_SGE, $context->builder->zext($len, $sizeT), $n);
        };
        $prefixEq = static function (string $prefix) use ($context, $data, $sizeT, $i32, $zero): Value {
            $n = strlen($prefix);
            $prefixPtr = $context->builder->pointerCast(
                $context->constantFromString($prefix),
                $context->getTypeFromString('int8*')
            );
            $cmp = $context->builder->call(
                $context->lookupFunction('strncmp'),
                $data,
                $prefixPtr,
                $sizeT->constInt($n, false)
            );

            return $context->builder->icmp(Builder::INT_EQ, $cmp, $zero);
        };
        $emitHttp = $context->builder->and($lenAtLeast($sizeT->constInt(5, false)), $prefixEq('HTTP/'));
        $emitLoc = $context->builder->and($lenAtLeast($sizeT->constInt(9, false)), $prefixEq('Location:'));
        $should = $context->builder->or($emitHttp, $emitLoc);
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof Value\Function_);
        $skip = $fn->appendBasicBlock('jit_hdr_skip');
        $do = $fn->appendBasicBlock('jit_hdr_do');
        $merge = $fn->appendBasicBlock('jit_hdr_merge');
        $context->builder->branchIf($should, $do, $skip);
        $context->builder->positionAtEnd($do);
        self::emit($context, $strPtr);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($skip);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
    }

    public static function emit(Context $context, Value $strPtr): void
    {
        $map = $context->structFieldMap['__string__'];
        $length = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $data = $context->builder->structGep($strPtr, $map['value']);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString("%.*s\r\n"),
            $context->getTypeFromString('char*')
        );
        $context->builder->call(
            $context->lookupFunction('printf'),
            $fmt,
            $length,
            $data
        );
    }
}
