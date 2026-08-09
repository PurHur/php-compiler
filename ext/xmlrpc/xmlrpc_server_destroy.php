<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\Frame;

/** xmlrpc_server_destroy() — close XML-RPC server resource (php-src xmlrpc-epi-php.c; #27879). */
final class xmlrpc_server_destroy extends XmlrpcFunction
{
    public function __construct()
    {
        parent::__construct('xmlrpc_server_destroy');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlrpc_server_destroy', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $serverVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmXmlrpcServer::handleFromVariable($serverVar);
        if (null === $handle) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(VmXmlrpcServer::destroy($handle, $serverVar));
    }
}
