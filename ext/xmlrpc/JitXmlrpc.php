<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin\StringXmlrpc;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** JIT xmlrpc_encode()/xmlrpc_decode() lowering via XmlrpcJitHelper PHP (#19048). */
final class JitXmlrpc
{
    public static function encode(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type || ArrayBuiltinHelper::isNativeArray($arg->type)) {
            StringXmlrpc::ensureArrayLinked($context);
            $ht = JITVariable::TYPE_HASHTABLE === $arg->type
                ? $context->helper->loadValue($arg)
                : ArrayBuiltinHelper::loadHashTable($context, $arg);

            return $context->builder->call(
                $context->lookupFunction('__compiler_xmlrpc_encode_array'),
                $ht
            );
        }

        StringXmlrpc::ensureEncodeLinked($context);
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__compiler_xmlrpc_encode_value'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::assignToPointer($context, $ptr, $arg);

        return $context->builder->call(
            $context->lookupFunction('__compiler_xmlrpc_encode_value'),
            $ptr
        );
    }

    public static function decode(Context $context, JITVariable $xmlArg): Value
    {
        StringXmlrpc::ensureDecodeLinked($context);

        $xmlString = JitStringBuiltinArg::lowerCoercible($context, $xmlArg, 'xmlrpc_decode', 0, 'xml');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_xmlrpc_decode'),
            $xmlString,
            $ptr
        );

        return $ptr;
    }
}
