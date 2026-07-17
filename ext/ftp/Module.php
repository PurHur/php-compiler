<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * ftp extension module entry (php-src ext/ftp/php_ftp.c; #7270, #3353, #19672).
 *
 * Register under {@see standard}; {@code extension_loaded('ftp')} follows
 * {@see FtpExtensionPolicy::advertisesExtension()} (paired with ftp_* / Ftp\Connection).
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
            new ftp_quit(),
            new ftp_login(),
            new ftp_fget(),
            new ftp_fput(),
            new ftp_mlsd(),
            new ftp_systype(),
            new ftp_nb_continue(),
            new ftp_nb_fget(),
            new ftp_nb_fput(),
            new ftp_nb_get(),
            new ftp_nb_put(),
            new ftp_pasv(),
            new ftp_get(),
            new ftp_put(),
            new ftp_nlist(),
            new ftp_rawlist(),
            new ftp_chdir(),
            new ftp_pwd(),
            new ftp_cdup(),
            new ftp_mkdir(),
            new ftp_delete(),
            new ftp_rename(),
            new ftp_rmdir(),
            new ftp_size(),
            new ftp_mdtm(),
            new ftp_append(),
            new ftp_alloc(),
            new ftp_chmod(),
            new ftp_raw(),
            new ftp_site(),
            new ftp_exec(),
            new ftp_set_option(),
            new ftp_get_option(),
        ];
    }
}
