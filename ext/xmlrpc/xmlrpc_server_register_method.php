<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/** xmlrpc_server_register_method() — bind method name → PHP callable (php-src xmlrpc-epi-php.c; #27879). */
final class xmlrpc_server_register_method extends XmlrpcFunction
{
    public function __construct()
    {
        parent::__construct('xmlrpc_server_register_method');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlrpc_server_register_method', 3);
        if (null === $frame->returnVar) {
            return;
        }
        $handle = VmXmlrpcServer::handleFromVariable($frame->calledArgs[0]->resolveIndirect());
        if (null === $handle) {
            $frame->returnVar->bool(false);

            return;
        }
        $method = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'xmlrpc_server_register_method',
            1,
            'method_name'
        );
        $frame->returnVar->bool(VmXmlrpcServer::registerMethod($handle, $method, $frame->calledArgs[2]));
    }
}
