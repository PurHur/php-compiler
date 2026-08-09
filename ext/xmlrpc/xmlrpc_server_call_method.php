<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** xmlrpc_server_call_method() — dispatch encoded request to registered callable (php-src; #27879). */
final class xmlrpc_server_call_method extends XmlrpcFunction
{
    public function __construct()
    {
        parent::__construct('xmlrpc_server_call_method');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'xmlrpc_server_call_method() expects 3 or 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $handle = VmXmlrpcServer::handleFromVariable($frame->calledArgs[0]->resolveIndirect());
        if (null === $handle) {
            $frame->returnVar->bool(false);

            return;
        }
        $xml = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'xmlrpc_server_call_method',
            1,
            'xml'
        );
        $phpOut = false;
        if (isset($frame->calledArgs[3])) {
            $opts = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $opts->type) {
                foreach ($opts->toArray()->iterateKeyed(true) as [$key, $value]) {
                    $k = $key->resolveIndirect();
                    $name = Variable::TYPE_STRING === $k->type ? $k->toString(null) : '';
                    if ('output_type' === $name) {
                        $v = $value->resolveIndirect();
                        if (Variable::TYPE_STRING === $v->type && 'php' === strtolower($v->toString(null))) {
                            $phpOut = true;
                        }
                    }
                }
            }
        }
        $result = VmXmlrpcServer::callMethod(
            $frame,
            $handle,
            $xml,
            $frame->calledArgs[2],
            $phpOut
        );
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }
}
