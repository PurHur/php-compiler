<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * sockets extension module entry (php-src ext/sockets/sockets.c; issue #6544, #19286, #25874).
 *
 * Registers as extension "sockets" once socket_create() is available (#11820).
 * socket_atmark() is PHP 8.3+ — gated like json_validate (#25874).
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'sockets';
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinEnums::register($runtime->vmContext);
        BuiltinClasses::register($runtime->vmContext);
        foreach (SocketConstants::registeredConstants() as $name => $value) {
            $var = new \PHPCompiler\VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        if (!VmSockets::isSocketApiSupported()) {
            return [];
        }

        return [
            ...(CompilerVersion::supportsSocketAtmark() ? [new socket_atmark()] : []),
            new socket_import_stream(),
            new socket_export_stream(),
            new socket_set_nonblock(),
            new socket_set_block(),
            new socket_create(),
            new socket_create_pair(),
            new socket_create_listen(),
            new socket_connect(),
            new socket_bind(),
            new socket_listen(),
            new socket_accept(),
            new socket_set_option(),
            new socket_get_option(),
            new socket_setopt(),
            new socket_getopt(),
            new socket_getsockname(),
            new socket_getpeername(),
            new socket_sendto(),
            new socket_recvfrom(),
            new socket_send(),
            new socket_recv(),
            new socket_addrinfo_lookup(),
            new socket_addrinfo_connect(),
            new socket_addrinfo_bind(),
            new socket_addrinfo_explain(),
            new socket_cmsg_space(),
            new socket_sendmsg(),
            new socket_recvmsg(),
            new socket_shutdown(),
            new socket_select(),
            new socket_read(),
            new socket_write(),
            new socket_close(),
            new socket_strerror(),
            new socket_last_error(),
            new socket_clear_error(),
        ];
    }
}
