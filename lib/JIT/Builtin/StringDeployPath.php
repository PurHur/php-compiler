<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __compiler_phpc_deploy_path (issue #585).
 */
final class StringDeployPath
{
    private const ENV_NAME = 'PHPC_DEPLOY_ROOT';

    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__compiler_phpc_deploy_path');
        $entry = $fn->appendBasicBlock('deploy_entry');
        $context->builder->positionAtEnd($entry);

        $rel = $fn->getParam(0);
        $fallback = $fn->getParam(1);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $i64->constInt(1, false);
        $zero = $i64->constInt(0, false);

        $envName = self::cStringLiteral($context, self::ENV_NAME);
        $envRoot = $context->builder->call($context->lookupFunction('getenv'), $envName);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $envRoot, $i8p->constNull());

        $checkEmpty = $fn->appendBasicBlock('deploy_check_empty');
        $useFallback = $fn->appendBasicBlock('deploy_use_fallback');
        $context->builder->branchIf($isNull, $useFallback, $checkEmpty);

        $context->builder->positionAtEnd($checkEmpty);
        $envLen = $context->builder->call($context->lookupFunction('strlen'), $envRoot);
        $envLenI64 = $envLen->typeOf() === $i64
            ? $envLen
            : $context->builder->zExt($envLen, $i64);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $envLenI64, $zero);
        $useEnv = $fn->appendBasicBlock('deploy_use_env');
        $context->builder->branchIf($isEmpty, $useFallback, $useEnv);

        $context->builder->positionAtEnd($useFallback);
        $context->builder->returnValue($fallback);

        $context->builder->positionAtEnd($useEnv);
        [$relLen, $relC, $relBuf] = self::stringToCStr($context, $rel, 'deploy_rel');
        $rootLen = $context->builder->call($context->lookupFunction('strlen'), $envRoot);
        $rootLenI64 = $rootLen->typeOf() === $i64
            ? $rootLen
            : $context->builder->zExt($rootLen, $i64);

        $relEmpty = $context->builder->icmp(Builder::INT_EQ, $relLen, $zero);
        $relOnlyRoot = $fn->appendBasicBlock('deploy_root_only');
        $relJoin = $fn->appendBasicBlock('deploy_join');
        $context->builder->branchIf($relEmpty, $relOnlyRoot, $relJoin);

        $context->builder->positionAtEnd($relOnlyRoot);
        $rootStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $rootLenI64,
            $envRoot
        );
        self::freeCStrBuf($context, $relBuf);
        $context->builder->returnValue($rootStr);

        $context->builder->positionAtEnd($relJoin);
        $bufLen = $context->builder->add(
            $context->builder->add($rootLenI64, $relLen),
            $one
        );
        $bufLen = $context->builder->add($bufLen, $one);
        $bufLenSizeT = $context->builder->truncOrBitCast($bufLen, $sizeT);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufLenSizeT);
        $bufC = $context->builder->pointerCast($buf, $i8p);
        $fmt = self::cStringLiteral($context, '%s/%s');
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufC,
            $bufLenSizeT,
            $fmt,
            $envRoot,
            $relC
        );
        $outLen = $context->builder->call($context->lookupFunction('strlen'), $bufC);
        $outLenI64 = $outLen->typeOf() === $i64
            ? $outLen
            : $context->builder->zExt($outLen, $i64);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $outLenI64,
            $bufC
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);
        self::freeCStrBuf($context, $relBuf);
        $context->builder->returnValue($result);

        $context->builder->clearInsertionPosition();
    }

    /**
     * @return array{0: Value, 1: Value, 2: Value} len, c-string ptr, backing buf (for free)
     */
    private static function stringToCStr(Context $context, Value $str, string $prefix): array
    {
        $strMap = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);

        $len = $context->builder->load(
            $context->builder->structGep($str, $strMap['length'])
        );
        $bytes = $context->builder->structGep($str, $strMap['value']);
        $bufLen = $context->builder->add($len, $one);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufLen);
            $ptr = $context->builder->pointerCast($buf, $i8p);
        } else {
            $buf = $context->builder->alloca($i8, $bufLen, $prefix.'_buf');
            $ptr = $context->builder->pointerCast($buf, $i8p);
        }
        $context->intrinsic->memcpy($ptr, $bytes, $len, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($ptr, $len)
        );

        return [$len, $ptr, $buf];
    }

    private static function freeCStrBuf(Context $context, Value $buf): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('__mm__free'), $buf);
        }
    }

    private static function cStringLiteral(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast($context->constantFromString($text), $i8p);
    }
}
