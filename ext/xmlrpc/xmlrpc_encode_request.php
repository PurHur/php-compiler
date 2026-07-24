<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** xmlrpc_encode_request() — methodCall XML (php-src ext/xmlrpc; #22254). */
final class xmlrpc_encode_request extends XmlrpcFunction
{
    public function __construct()
    {
        parent::__construct('xmlrpc_encode_request');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'xmlrpc_encode_request() expects 2 or 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $methodArg = $frame->calledArgs[0]->resolveIndirect();
        $method = null;
        if (Variable::TYPE_NULL !== $methodArg->type) {
            $method = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[0],
                'xmlrpc_encode_request',
                0,
                'method'
            );
        }
        // Optional $output_options accepted for signature parity; ignored in v1.
        $xml = VmXmlrpc::encodeRequest($method, $frame->calledArgs[1]);
        $frame->returnVar->string($xml);
    }
}
