<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\Frame;

/** xmlrpc_is_fault() — detect faultCode/faultString arrays (php-src ext/xmlrpc; #22254). */
final class xmlrpc_is_fault extends XmlrpcFunction
{
    public function __construct()
    {
        parent::__construct('xmlrpc_is_fault');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlrpc_is_fault', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmXmlrpc::isFault($frame->calledArgs[0]));
    }
}
