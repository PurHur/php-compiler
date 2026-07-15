<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** xmlrpc_decode() — XML-RPC value deserialization (php-src ext/xmlrpc/xmlrpc.c; #6579). */
final class xmlrpc_decode extends XmlrpcFunction
{
    public function __construct()
    {
        parent::__construct('xmlrpc_decode');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \LogicException('xmlrpc_decode() requires at least one argument');
        }
        if ($argc > 2) {
            throw new \LogicException('xmlrpc_decode() accepts at most two arguments');
        }

        $falseLiteral = JitXmlrpcCompileTime::tryDecodeFalseLiteral($context, $args[0]);
        if (null !== $falseLiteral) {
            return $falseLiteral;
        }

        return JitXmlrpc::decode($context, $args[0]);
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'xmlrpc_decode', 1);
        $this->requireAtMostArgCount($frame, 'xmlrpc_decode', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $xml = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'xmlrpc_decode',
            0,
            'xml'
        );
        if (isset($frame->calledArgs[1])) {
            VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'xmlrpc_decode',
                1,
                'encoding'
            );
        }
        $decoded = VmXmlrpc::decode($xml);
        if (false === $decoded) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($decoded));
    }
}
