<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** xmlrpc_decode_request() — decode methodCall/methodResponse (php-src ext/xmlrpc; #22254). */
final class xmlrpc_decode_request extends XmlrpcFunction
{
    public function __construct()
    {
        parent::__construct('xmlrpc_decode_request');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'xmlrpc_decode_request() expects 2 or 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $xml = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'xmlrpc_decode_request',
            0,
            'xml'
        );
        if (isset($frame->calledArgs[2])) {
            VmString::coerceStringBuiltinArg(
                $frame->calledArgs[2],
                'xmlrpc_decode_request',
                2,
                'encoding'
            );
        }
        $method = '';
        $decoded = VmXmlrpc::decodeRequest($xml, $method);
        $methodSlot = $frame->calledArgs[1]->resolveIndirect();
        $methodSlot->string($method);
        if (false === $decoded) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($decoded));
    }
}
