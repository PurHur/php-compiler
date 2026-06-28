<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamBucket;
use PHPCompiler\JIT\Builtin\StreamFilter as StreamFilterBuiltin;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for is_resource() via __compiler_is_resource (#3519, #6323 bucket/brigade). */
final class JitIsResource
{
    public static function invoke(Context $context, Value $handleLong): Value
    {
        StreamBucket::ensureLinked($context);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $bucketBase = $i64->constInt(\PHPCompiler\JIT\Builtin\StreamBucketRuntime::BUCKET_HANDLE_BASE, false);
        $filterBase = $i64->constInt(StreamFilterJitHelper::HANDLE_BASE, false);
        $trueVal = $context->constantFromBool(true);
        $falseVal = $context->constantFromBool(false);
        $isBucketRange = $context->builder->icmp(Builder::INT_SGE, $handleLong, $bucketBase);
        $bucketProbe = BasicBlockHelper::append($context, 'is_resource_bucket_probe');
        $filterProbe = BasicBlockHelper::append($context, 'is_resource_filter_probe');
        $done = BasicBlockHelper::append($context, 'is_resource_done');
        $context->builder->branchIf($isBucketRange, $bucketProbe, $filterProbe);

        $context->builder->positionAtEnd($filterProbe);
        $isFilterRange = $context->builder->icmp(Builder::INT_SGE, $handleLong, $filterBase);
        $streamProbe = BasicBlockHelper::append($context, 'is_resource_stream_probe');
        $filterCheck = BasicBlockHelper::append($context, 'is_resource_filter_check');
        $context->builder->branchIf($isFilterRange, $filterCheck, $streamProbe);

        $context->builder->positionAtEnd($filterCheck);
        StreamFilterBuiltin::ensureLinked($context);
        $isFilter = $context->builder->call(
            $context->lookupFunction('__compiler_is_stream_filter_resource'),
            $handleLong
        );
        $filterOk = $context->builder->icmp(Builder::INT_NE, $isFilter, $zeroI32);
        $filterTrue = BasicBlockHelper::append($context, 'is_resource_filter_true');
        $context->builder->branchIf($filterOk, $filterTrue, $streamProbe);

        $context->builder->positionAtEnd($filterTrue);
        $filterEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($bucketProbe);
        $isBucket = $context->builder->call(
            $context->lookupFunction('__compiler_is_bucket_resource'),
            $handleLong
        );
        $bucketOk = $context->builder->icmp(Builder::INT_NE, $isBucket, $zeroI32);
        $brigadeProbe = BasicBlockHelper::append($context, 'is_resource_brigade_probe');
        $bucketTrue = BasicBlockHelper::append($context, 'is_resource_bucket_true');
        $context->builder->branchIf($bucketOk, $bucketTrue, $brigadeProbe);

        $context->builder->positionAtEnd($bucketTrue);
        $bucketEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($brigadeProbe);
        $isBrigade = $context->builder->call(
            $context->lookupFunction('__compiler_is_brigade_resource'),
            $handleLong
        );
        $brigadeOk = $context->builder->icmp(Builder::INT_NE, $isBrigade, $zeroI32);
        $brigadeTrue = BasicBlockHelper::append($context, 'is_resource_brigade_true');
        $context->builder->branchIf($brigadeOk, $brigadeTrue, $streamProbe);

        $context->builder->positionAtEnd($brigadeTrue);
        $brigadeEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($streamProbe);
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_is_resource'),
            $handleLong
        );
        $streamOk = $context->builder->icmp(Builder::INT_EQ, $ret, $oneI32);
        $streamResult = $context->builder->select($streamOk, $trueVal, $falseVal);
        $streamEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i1, 'is_resource_phi');
        $phi->addIncoming($trueVal, $bucketEnd);
        $phi->addIncoming($trueVal, $brigadeEnd);
        $phi->addIncoming($trueVal, $filterEnd);
        $phi->addIncoming($streamResult, $streamEnd);

        return $phi;
    }
}
