<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * ftp extension module entry — phase 0: FTP\Connection class only (php-src ext/ftp/ftp.stub.php; #7270).
 *
 * Register under {@see standard}; {@code extension_loaded('ftp')} stays false until ftp_connect()
 * and handlers land (#3353).
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
        if (!CompilerVersion::supportsFtpConnection()) {
            return;
        }
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        return [];
    }
}
