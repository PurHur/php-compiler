<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/** xmlrpc_parse_method_descriptions() — minimal XML → array (php-src xmlrpc-epi-php.c; #27879). */
final class xmlrpc_parse_method_descriptions extends XmlrpcFunction
{
    public function __construct()
    {
        parent::__construct('xmlrpc_parse_method_descriptions');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'xmlrpc_parse_method_descriptions', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $xml = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'xmlrpc_parse_method_descriptions',
            0,
            'xml'
        );
        $parsed = VmXmlrpcServer::parseMethodDescriptions($xml);
        if (false === $parsed) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($parsed));
    }
}
