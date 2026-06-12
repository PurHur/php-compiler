<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * sockets extension module entry (php-src ext/sockets/sockets.c; issue #6544).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinEnums::register($runtime->vmContext);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        if (!VmSockets::isAtmarkSupported()) {
            return [];
        }

        $fns = [
            new socket_atmark(),
            new socket_import_stream(),
        ];

        return $fns;
    }
}
