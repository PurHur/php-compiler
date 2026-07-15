<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * ftp extension module entry (php-src ext/ftp/php_ftp.c; #7270, #3353).
 *
 * Register under {@see standard}; {@code extension_loaded('ftp')} stays false until
 * {@see FtpExtensionPolicy::advertisesExtension()} (#3353 phase 2).
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'standard';
    }

    /**
     * @return list<string>
     */
    public function getAdditionalExtensionNames(): array
    {
        if (!FtpExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return ['ftp'];
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!CompilerVersion::supportsFtpConnection()) {
            return;
        }
        BuiltinClasses::register($runtime->vmContext);
        foreach (FtpConstants::registeredConstants() as $name => $value) {
            $var = new \PHPCompiler\VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        if (!FtpExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new ftp_connect(),
            new ftp_ssl_connect(),
            new ftp_close(),
            new ftp_login(),
            new ftp_fget(),
            new ftp_fput(),
            new ftp_mlsd(),
            new ftp_systype(),
        ];
    }
}
