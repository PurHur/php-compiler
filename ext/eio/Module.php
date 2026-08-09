<?php

declare(strict_types=1);

namespace PHPCompiler\ext\eio;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Variable;

/**
 * eio extension module entry (PECL eio / libeio; #6442, #27837).
 *
 * Pure-PHP request queue completed on eio_poll() — no runtime/*.c / libeio required.
 * Advertise when {@see EioExtensionPolicy::advertisesExtension()}. JIT/AOT: VM-only v1.
 */
class Module extends ModuleAbstract
{
    private const EIO_VERSION = '3.1.0';

    public function getExtensionVersion(): string
    {
        return self::EIO_VERSION;
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!EioExtensionPolicy::advertisesExtension()) {
            return;
        }
        BuiltinClasses::register($runtime->vmContext);
        foreach (EioConstants::registeredConstants() as $name => $value) {
            $var = new Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        if (!EioExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        require_once __DIR__.'/eio_functions.php';

        return [
            new eio_init(),
            new eio_nop(),
            new eio_open(),
            new eio_close(),
            new eio_read(),
            new eio_write(),
            new eio_stat(),
            new eio_mkdir(),
            new eio_unlink(),
            new eio_readdir(),
            new eio_chmod(),
            new eio_poll(),
            new eio_nreqs(),
        ];
    }
}
