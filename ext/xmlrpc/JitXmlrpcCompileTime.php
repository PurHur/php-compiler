<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\Block;
use PHPCompiler\JIT\CallUnpackHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCfg\Operand;
use PHPLLVM\Value;

/**
 * Compile-time xmlrpc_encode()/xmlrpc_decode() for literals — avoids nested AOT helper runtime (#19048).
 *
 * php-src: ext/xmlrpc/xmlrpc.c
 */
final class JitXmlrpcCompileTime
{
    public static function tryEncode(
        Context $context,
        JITVariable $arg,
        ?Block $block,
        ?Operand $operand
    ): ?Value {
        $vmValue = self::compileTimeVmValue($arg, $block, $operand);
        if (null === $vmValue) {
            return null;
        }
        try {
            $xml = VmXmlrpc::encode($vmValue);
        } catch (\Throwable) {
            return null;
        }

        return $context->builder->load($context->constantStringFromString($xml));
    }

    /** Compile-time xmlrpc_decode() when XML literal is known invalid (returns false). */
    public static function tryDecodeFalseLiteral(Context $context, JITVariable $xmlArg): ?Value
    {
        $literal = $xmlArg->compileTimeString;
        if (null === $literal) {
            return null;
        }
        if (false !== VmXmlrpc::decode($literal)) {
            return null;
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return $ptr;
    }

    private static function compileTimeVmValue(
        JITVariable $arg,
        ?Block $block,
        ?Operand $operand
    ): ?Variable {
        if (null !== $block && null !== $operand) {
            $array = CallUnpackHelper::tryCompileTimeArrayFromOperand($block, $operand);
            if (null !== $array) {
                return $array;
            }
        }
        if (null !== $arg->compileTimeLong) {
            $value = new Variable(Variable::TYPE_INTEGER);
            $value->integer($arg->compileTimeLong);

            return $value;
        }
        if (null !== $arg->compileTimeFloat) {
            $value = new Variable(Variable::TYPE_FLOAT);
            $value->float($arg->compileTimeFloat);

            return $value;
        }
        if (null !== $arg->compileTimeString) {
            $value = new Variable(Variable::TYPE_STRING);
            $value->string($arg->compileTimeString);

            return $value;
        }
        if (JITVariable::TYPE_BOOLEAN === $arg->type && null !== $arg->value) {
            $value = new Variable(Variable::TYPE_BOOLEAN);
            $value->bool((bool) $arg->value);

            return $value;
        }

        return null;
    }
}
