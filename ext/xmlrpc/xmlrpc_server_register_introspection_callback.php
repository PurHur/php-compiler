<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\Frame;

/** xmlrpc_server_register_introspection_callback() — queue doc callback (php-src; #27879). */
final class xmlrpc_server_register_introspection_callback extends XmlrpcFunction
{
    public function __construct()
    {
        parent::__construct('xmlrpc_server_register_introspection_callback');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlrpc_server_register_introspection_callback', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $handle = VmXmlrpcServer::handleFromVariable($frame->calledArgs[0]->resolveIndirect());
        if (null === $handle) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(
            VmXmlrpcServer::registerIntrospectionCallback($handle, $frame->calledArgs[1])
        );
    }
}
