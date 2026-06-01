<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringNetworkServices;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for getprotobynumber() / getservbyport() (JIT/AOT, issue #3650). */
final class JitNetworkServices
{
    private static int $blockSerial = 0;

    public static function getprotobynumber(Context $context, JITVariable $number): Value
    {
        StringNetworkServices::ensureLinked($context);

        return self::boxedString(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_getprotobynumber'),
                self::jitIntArg($context, $number, 'getprotobynumber() protocol number')
            )
        );
    }

    public static function getservbyport(Context $context, JITVariable $port, JITVariable $protocol): Value
    {
        StringNetworkServices::ensureLinked($context);

        return self::boxedString(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_getservbyport'),
                self::jitIntArg($context, $port, 'getservbyport() port'),
                JitStringArg::lower($context, $protocol, 'getservbyport() protocol')
            )
        );
    }

    private static function jitIntArg(Context $context, JITVariable $arg, string $label): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $arg->value
            );
        }

        throw new \LogicException($label.' must be an integer in this compiler build');
    }

    private static function boxedString(Context $context, Value $nameStr): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $nameStr, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'netsvc_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'netsvc_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'netsvc_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $nameStr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
