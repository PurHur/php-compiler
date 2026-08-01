<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ssh2;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * ssh2 extension module entry (PECL ssh2 / libssh2; #6385).
 *
 * Phase-1 procedural API — PHP-in-PHP; optional libssh2 FFI when present.
 * Advertise when {@see Ssh2ExtensionPolicy::advertisesExtension()}. JIT/AOT: VM-only v1.
 */
class Module extends ModuleAbstract
{
    private const SSH2_VERSION = '1.4.1';

    public function getExtensionVersion(): string
    {
        return self::SSH2_VERSION;
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!Ssh2ExtensionPolicy::advertisesExtension()) {
            return;
        }
        VmSsh2Session::registerClass($runtime->vmContext);
        VmSsh2Stream::registerClass($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        if (!Ssh2ExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        require_once __DIR__.'/ssh2_functions.php';

        return [
            new ssh2_connect(),
            new ssh2_disconnect(),
            new ssh2_auth_password(),
            new ssh2_fingerprint(),
            new ssh2_exec(),
            new ssh2_fetch_stream(),
        ];
    }
}
