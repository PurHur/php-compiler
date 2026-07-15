<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** xmlrpc_encode() — XML-RPC value serialization (php-src ext/xmlrpc/xmlrpc.c; #6579). */
final class xmlrpc_encode extends XmlrpcFunction
{
    public function __construct()
    {
        parent::__construct('xmlrpc_encode');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('xmlrpc_encode() requires exactly one argument');
        }

        $encoded = JitXmlrpcCompileTime::tryEncode(
            $context,
            $args[0],
            $context->jitEnclosingBlock,
            $context->jitXmlrpcEncodeValueOperand
        );
        if (null !== $encoded) {
            return $encoded;
        }

        return JitXmlrpc::encode($context, $args[0]);
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlrpc_encode', 1);
        if (null === $frame->returnVar) {
            return;
        }
        try {
            $xml = VmXmlrpc::encode($frame->calledArgs[0]);
        } catch (\Throwable $e) {
            throw new \Exception($e->getMessage(), 0, $e);
        }
        $frame->returnVar->string($xml);
    }
}
