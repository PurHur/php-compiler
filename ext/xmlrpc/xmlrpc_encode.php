<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\Frame;

/** xmlrpc_encode() — XML-RPC value serialization (php-src ext/xmlrpc/xmlrpc.c; #6579). */
final class xmlrpc_encode extends XmlrpcFunction
{
    public function __construct()
    {
        parent::__construct('xmlrpc_encode');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlrpc_encode', 1);
        if (null === $frame->returnVar) {
            return;
        }
        try {
            $xml = VmXmlrpc::encode($frame->calledArgs[0]);
        } catch (\Throwable $e) {
            throw new \Exception($e->getMessage(), 0, $e);
        }
        $frame->returnVar->string($xml);
    }
}
