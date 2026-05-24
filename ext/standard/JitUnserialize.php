<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringUnserialize;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitUnserialize
{
    private static int $blockSerial = 0;

    public static function decodeRuntime(Context $context, JITVariable $data): Value
    {
        return self::decodeRuntimeString(
            $context,
            JitStringArg::lower($context, $data, 'unserialize() data')
        );
    }

    /** @return Value __value__* */
    public static function decodeRuntimeString(Context $context, Value $dataString): Value
    {
        StringUnserialize::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_unserialize'),
            $dataString,
            $ptr
        );
        $failed = $context->builder->icmp(Builder::INT_EQ, $ok, $i64->constInt(0, false));

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'unser_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'unser_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'unser_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * @return Value|null __value__* when payload is a compile-time string literal
     */
    public static function compileTimeDecode(Context $context, JITVariable $data): ?Value
    {
        $literal = JitStringArg::compileTimeLiteral($data);
        if (null === $literal) {
            return null;
        }
        $decoded = @\unserialize($literal, ['allowed_classes' => false]);
        if (false === $decoded) {
            return JitJsonDecode::materializeScalar($context, false);
        }
        if (null === $decoded) {
            return JitJsonDecode::materializeNull($context);
        }
        if (\is_array($decoded)) {
            $ht = JitJsonDecode::materializeArray($context, $decoded);
            $context->refcount->addref($ht);
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                JitValueBox::pointer($context, $slot),
                $ht
            );

            return JitValueBox::pointer($context, $slot);
        }
        if (\is_bool($decoded) || \is_int($decoded) || \is_float($decoded) || \is_string($decoded)) {
            return JitJsonDecode::materializeScalar($context, $decoded);
        }

        throw new \LogicException('unserialize() result type not supported in this compiler build');
    }
}
