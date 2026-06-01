<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM helpers for md5_file() / sha1_file() — read path then __compiler_hash (issue #3590). */
final class JitHashFile
{
    private static int $blockSerial = 0;

    public static function md5(Context $context, Value $path, Value $raw): Value
    {
        return self::hashFile(
            $context,
            $path,
            $raw,
            static fn (Context $ctx, Value $data, Value $r) => JitMd5::digest($ctx, $data, $r)
        );
    }

    public static function sha1(Context $context, Value $path, Value $raw): Value
    {
        return self::hashFile(
            $context,
            $path,
            $raw,
            static fn (Context $ctx, Value $data, Value $r) => JitSha1::digest($ctx, $data, $r)
        );
    }

    /**
     * @param callable(Context, Value, Value): Value $digest
     */
    private static function hashFile(
        Context $context,
        Value $path,
        Value $raw,
        callable $digest
    ): Value {
        $contentsPtr = JitFileGetContents::invoke($context, $path);

        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($contentsPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $stringTag = $i8->constInt(JITVariable::TYPE_STRING, false);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTag);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'hash_file_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'hash_file_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'hash_file_done_'.$id);
        $context->builder->branchIf($isString, $okBlock, $failBlock);

        $context->builder->positionAtEnd($failBlock);
        $failResult = self::boxedFalse($context);
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $data = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $contentsPtr
        );
        $okResult = $digest($context, $data, $raw);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($failResult->typeOf());
        $phi->addIncoming($failResult, $failTail);
        $phi->addIncoming($okResult, $okTail);

        return $phi;
    }

    private static function boxedFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }
}
