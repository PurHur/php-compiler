<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ssh2;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * ssh2 extension module entry (PECL ssh2 / libssh2; #6385).
 *
 * Phase-1b procedural API — PHP-in-PHP; real libssh2 FFI handshake when present (#26509).
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
        require_once __DIR__.'/Ssh2Constants.php';
        foreach (Ssh2Constants::registeredConstants() as $name => $value) {
            $var = new \PHPCompiler\VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
        VmSsh2Session::registerClass($runtime->vmContext);
        VmSsh2Stream::registerClass($runtime->vmContext);
        VmSsh2Sftp::registerClass($runtime->vmContext);
        require_once __DIR__.'/VmSsh2Listener.php';
        VmSsh2Listener::registerClass($runtime->vmContext);
        require_once __DIR__.'/VmSsh2Publickey.php';
        VmSsh2Publickey::registerClass($runtime->vmContext);
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
            new ssh2_auth_none(),
            new ssh2_auth_agent(),
            new ssh2_auth_hostbased_file(),
            new ssh2_methods_negotiated(),
            new ssh2_auth_pubkey_file(),
            new ssh2_auth_pubkey(),
            new ssh2_fingerprint(),
            new ssh2_exec(),
            new ssh2_fetch_stream(),
            new ssh2_send_eof(),
            new ssh2_send_signal(),
            new ssh2_keepalive_config(),
            new ssh2_keepalive_send(),
            new ssh2_set_timeout(),
            new ssh2_shell_resize(),
            new ssh2_shell(),
            new ssh2_tunnel(),
            new ssh2_forward_listen(),
            new ssh2_forward_accept(),
            new ssh2_sftp(),
            new ssh2_scp_recv(),
            new ssh2_scp_send(),
            new ssh2_sftp_stat(),
            new ssh2_sftp_lstat(),
            new ssh2_sftp_mkdir(),
            new ssh2_sftp_rmdir(),
            new ssh2_sftp_unlink(),
            new ssh2_sftp_rename(),
            new ssh2_sftp_chmod(),
            new ssh2_sftp_realpath(),
            new ssh2_sftp_statvfs(),
            new ssh2_sftp_symlink(),
            new ssh2_sftp_readlink(),
            new ssh2_publickey_init(),
            new ssh2_publickey_add(),
            new ssh2_publickey_remove(),
            new ssh2_publickey_list(),
        ];
    }
}
