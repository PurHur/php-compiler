<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/** xmlrpc_server_add_introspection_data() — store desc payload (php-src xmlrpc-epi-php.c; #27879). */
final class xmlrpc_server_add_introspection_data extends XmlrpcFunction
{
    public function __construct()
    {
        parent::__construct('xmlrpc_server_add_introspection_data');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlrpc_server_add_introspection_data', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $handle = VmXmlrpcServer::handleFromVariable($frame->calledArgs[0]->resolveIndirect());
        if (null === $handle) {
            $frame->returnVar->int(0);

            return;
        }
        $descArg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $descArg->type) {
            throw new \TypeError(
                'xmlrpc_server_add_introspection_data(): Argument #2 ($desc) must be of type array, '
                .EnumCaseSupport::typeNameForVariable($descArg).' given'
            );
        }
        $exported = VmJson::export($descArg);
        $frame->returnVar->int(VmXmlrpcServer::addIntrospectionData($handle, $exported));
    }
}
