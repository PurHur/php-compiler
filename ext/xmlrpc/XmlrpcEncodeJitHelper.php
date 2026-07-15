<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\VM\Variable;

/**
 * xmlrpc_encode() JIT/AOT helper (#19048).
 *
 * SSOT: {@see VmXmlrpc::encode()}
 */
final class XmlrpcEncodeJitHelper
{
    public static function encodeValue(Variable $value): string
    {
        return VmXmlrpc::encode($value);
    }
}
