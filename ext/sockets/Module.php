<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * sockets extension module entry (php-src ext/sockets/sockets.c; issue #6544).
 *
 * Constants register at init; {@see standard\Module} advertises the logical
 * {@code sockets} extension for extension_loaded() / get_defined_constants(true)
 * buckets (#17799, #18083). Socket builtins remain partial (#3399, #11820).
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'standard';
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
        if (!VmSockets::isAtmarkSupported()) {
            return [];
        }

        $fns = [
            new socket_atmark(),
            new socket_import_stream(),
            new socket_export_stream(),
            new socket_set_nonblock(),
            new socket_set_block(),
        ];

        return $fns;
    }
}
