<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/** xmlrpc_set_type() — mark value as base64/datetime (php-src ext/xmlrpc; #22254). */
final class xmlrpc_set_type extends XmlrpcFunction
{
    public function __construct()
    {
        parent::__construct('xmlrpc_set_type');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlrpc_set_type', 2);
        $type = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'xmlrpc_set_type',
            1,
            'type'
        );
        $ok = VmXmlrpc::setType($frame->calledArgs[0], $type);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}
