<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\ModuleAbstract;

/**
 * xmlrpc extension module entry (php-src ext/xmlrpc/xmlrpc.c; issue #6579, #18503).
 *
 * PHP-in-PHP XML-RPC encode/decode — no runtime/*.c growth. Advertise logical {@code xmlrpc}
 * extension and xmlrpc_encode()/xmlrpc_decode() plus request/type helpers when
 * {@see XmlrpcExtensionPolicy::advertisesExtension()} — withheld on reference profile
 * (Zend 8.2 has no ext/xmlrpc).
 */
class Module extends ModuleAbstract
{
    /** php-src ext/xmlrpc/php_xmlrpc.h PHP_XMLRPC_VERSION */
    private const XMLRPC_VERSION = '1.0.0RC51';

    public function getExtensionVersion(): string
    {
        return self::XMLRPC_VERSION;
    }

    public function getFunctions(): array
    {
        if (!XmlrpcExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new xmlrpc_encode(),
            new xmlrpc_decode(),
            new xmlrpc_encode_request(),
            new xmlrpc_decode_request(),
            new xmlrpc_is_fault(),
            new xmlrpc_get_type(),
            new xmlrpc_set_type(),
            new xmlrpc_server_create(),
            new xmlrpc_server_destroy(),
            new xmlrpc_server_register_method(),
            new xmlrpc_server_call_method(),
            new xmlrpc_parse_method_descriptions(),
            new xmlrpc_server_add_introspection_data(),
            new xmlrpc_server_register_introspection_callback(),
        ];
    }
}
