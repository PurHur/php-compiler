<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * gnupg extension module entry (PECL gnupg; #6668, #25360).
 *
 * Advertise gnupg_* / class gnupg / extension_loaded('gnupg') only when
 * {@see GnupgExtensionPolicy::advertisesExtension()}. Requires libgpgme via FFI
 * for runtime ops — see Docker/dev/ubuntu-22.04/Dockerfile.
 */
class Module extends ModuleAbstract
{
    public function getExtensionVersion(): string
    {
        return '1.5.4';
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!GnupgExtensionPolicy::advertisesClasses()) {
            return;
        }
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        if (!GnupgExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new gnupg_init(),
            new gnupg_addencryptkey(),
            new gnupg_adddecryptkey(),
            new gnupg_addsignkey(),
            new gnupg_encrypt(),
            new gnupg_decrypt(),
            new gnupg_sign(),
            new gnupg_verify(),
            new gnupg_cleardecryptkeys(),
            new gnupg_clearencryptkeys(),
            new gnupg_clearsignkeys(),
            new gnupg_geterror(),
            new gnupg_keyinfo(),
        ];
    }
}
