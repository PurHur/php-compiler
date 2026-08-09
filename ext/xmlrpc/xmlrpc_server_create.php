<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\Frame;

/** xmlrpc_server_create() — allocate XML-RPC server resource (php-src xmlrpc-epi-php.c; #27879). */
final class xmlrpc_server_create extends XmlrpcFunction
{
    public function __construct()
    {
        parent::__construct('xmlrpc_server_create');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlrpc_server_create', 0);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('xmlrpc_server_create() requires an active VM context');
        }
        $id = VmXmlrpcServer::create();
        VmXmlrpcServer::wrapResource($frame->returnVar, $id, $frame->vmContext);
    }
}
