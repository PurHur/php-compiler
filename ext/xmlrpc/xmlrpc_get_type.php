<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\Frame;

/** xmlrpc_get_type() — XML-RPC type name (php-src ext/xmlrpc; #22254). */
final class xmlrpc_get_type extends XmlrpcFunction
{
    public function __construct()
    {
        parent::__construct('xmlrpc_get_type');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlrpc_get_type', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmXmlrpc::getType($frame->calledArgs[0]));
    }
}
