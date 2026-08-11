<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringNetworkServices;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for getprotobynumber()/getservbyport()/getprotobyname()/getservbyname() (JIT/AOT, issues #3650, #4024, #30283). */
final class JitNetworkServices
{
    private static int $blockSerial = 0;

    public static function getprotobynumber(Context $context, JITVariable $number): Value
    {
        StringNetworkServices::ensureStringReturnLinked($context);

        // Z_PARAM_LONG — honor caller strict_types; soft-null DEP+coerce (#30283).
        return self::boxedString(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_getprotobynumber'),
                JitIntdiv::lowerIntBuiltinArgForCaller($context, $number, 'getprotobynumber', 1, 'protocol')
            )
        );
    }

    public static function getprotobyname(Context $context, JITVariable $name): Value
    {
        StringNetworkServices::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        // Z_PARAM_STR — honor caller strict_types; soft-null DEP+coerce (#30282).
        $context->builder->call(
            $context->lookupFunction('__phpc_getprotobyname'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $name, 'getprotobyname', 0, 'protocol', 'string', null, false),
            $ptr
        );

        return $ptr;
    }

    public static function getservbyport(Context $context, JITVariable $port, JITVariable $protocol): Value
    {
        StringNetworkServices::ensureStringReturnLinked($context);

        // Z_PARAM_LONG port — honor caller strict_types; soft-null DEP+coerce (#30283).
        return self::boxedString(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_getservbyport'),
                JitIntdiv::lowerIntBuiltinArgForCaller($context, $port, 'getservbyport', 1, 'port'),
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $protocol, 'getservbyport', 1, 'protocol', 'string', null, false)
            )
        );
    }

    public static function getservbyname(Context $context, JITVariable $service, JITVariable $protocol): Value
    {
        StringNetworkServices::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        // Z_PARAM_STR — honor caller strict_types; soft-null DEP+coerce (#30281).
        $context->builder->call(
            $context->lookupFunction('__phpc_getservbyname'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $service, 'getservbyname', 0, 'service', 'string', null, false),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $protocol, 'getservbyname', 1, 'protocol', 'string', null, false),
            $ptr
        );

        return $ptr;
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
