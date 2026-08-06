<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ssh2;

/**
 * Optional libssh2 FFI + TCP connect (PECL ssh2; #6385 / #26509).
 *
 * Ubuntu 22.04 CI image has no libssh2 by default — connect uses a short TCP probe and
 * returns false with a Zend-style warning when the host/port is unreachable. When
 * libssh2.so is present, {@see handshake()} / {@see authPassword()} drive a real session.
 * No new runtime C.
 */
final class VmSsh2Native
{
    /** @var \FFI|null|false */
    private static $ffi = false;

    /** @var \FFI|null|false */
    private static $libc = false;

    private static bool $libssh2Inited = false;

    public static function hasLibssh2(): bool
    {
        return null !== self::ffi();
    }

    /**
     * Probe TCP reachability of host:port (PECL fails similarly when the daemon is down).
     */
    public static function tcpProbe(string $host, int $port, float $timeoutSec = 0.25): bool
    {
        $errno = 0;
        $errstr = '';
        $fp = @\fsockopen($host, $port, $errno, $errstr, $timeoutSec);
        if (false === $fp) {
            return false;
        }
        fclose($fp);

        return true;
    }

    /**
     * Open TCP + libssh2 session handshake.
     *
     * @return array{sock: int, session: \FFI\CData}|null
     */
    public static function handshake(string $host, int $port, float $timeoutSec = 10.0): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        self::ensureLibssh2Init($ffi);
        $sock = self::tcpConnectFd($host, $port, $timeoutSec);
        if (null === $sock) {
            return null;
        }
        $session = $ffi->libssh2_session_init_ex(null, null, null, null);
        if (null === $session) {
            self::closeFd($sock);

            return null;
        }
        $ffi->libssh2_session_set_blocking($session, 1);
        $rc = $ffi->libssh2_session_handshake($session, $sock);
        if (0 !== $rc) {
            $ffi->libssh2_session_free($session);
            self::closeFd($sock);

            return null;
        }

        return ['sock' => $sock, 'session' => $session];
    }

    /**
     * @param \FFI\CData $session LIBSSH2_SESSION*
     */
    public static function authPassword(\FFI\CData $session, string $username, string $password): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $rc = $ffi->libssh2_userauth_password_ex(
            $session,
            $username,
            \strlen($username),
            $password,
            \strlen($password),
            null
        );

        return 0 === $rc;
    }

    /**
     * Public-key auth from files (PECL ssh2_auth_pubkey_file; #26577).
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     */
    public static function authPubkeyFromFile(
        \FFI\CData $session,
        string $username,
        string $pubkeyFile,
        string $privkeyFile,
        ?string $passphrase
    ): bool {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $rc = $ffi->libssh2_userauth_publickey_fromfile_ex(
            $session,
            $username,
            \strlen($username),
            $pubkeyFile,
            $privkeyFile,
            $passphrase
        );

        return 0 === $rc;
    }

    /**
     * Public-key auth from in-memory key blobs (PECL ssh2_auth_pubkey; #26716).
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     */
    public static function authPubkeyFromMemory(
        \FFI\CData $session,
        string $username,
        string $pubkey,
        string $privkey,
        ?string $passphrase
    ): bool {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        try {
            $rc = $ffi->libssh2_userauth_publickey_frommemory(
                $session,
                $username,
                \strlen($username),
                $pubkey,
                \strlen($pubkey),
                $privkey,
                \strlen($privkey),
                $passphrase
            );
        } catch (\Throwable) {
            return false;
        }

        return 0 === $rc;
    }

    /**
     * Hostbased public-key auth from files (PECL ssh2_auth_hostbased_file; #26714).
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     */
    public static function authHostbasedFromFile(
        \FFI\CData $session,
        string $username,
        string $hostname,
        string $pubkeyFile,
        string $privkeyFile,
        ?string $passphrase,
        string $localUsername
    ): bool {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        try {
            $rc = $ffi->libssh2_userauth_hostbased_fromfile_ex(
                $session,
                $username,
                \strlen($username),
                $pubkeyFile,
                $privkeyFile,
                $passphrase,
                $hostname,
                \strlen($hostname),
                $localUsername,
                \strlen($localUsername)
            );
        } catch (\Throwable) {
            return false;
        }

        return 0 === $rc;
    }

    /**
     * Authenticate via local ssh-agent (PECL ssh2_auth_agent; #26713).
     *
     * Mirrors pecl-networking-ssh2 `PHP_FUNCTION(ssh2_auth_agent)`:
     * init → connect → list_identities → try each identity with userauth.
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     *
     * @return true|string  true on success; warning message string on failure
     */
    public static function authAgent(\FFI\CData $session, string $username)
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return 'Failure initializing ssh-agent support';
        }
        try {
            $userauthlist = $ffi->libssh2_userauth_list($session, $username, \strlen($username));
        } catch (\Throwable) {
            return 'Failure initializing ssh-agent support';
        }
        if (null !== $userauthlist) {
            try {
                $list = \FFI::string($userauthlist);
            } catch (\Throwable) {
                $list = '';
            }
            if ('' !== $list && false === \strpos($list, 'publickey')) {
                return '"publickey" authentication is not supported';
            }
        }
        try {
            $agent = $ffi->libssh2_agent_init($session);
        } catch (\Throwable) {
            return 'Failure initializing ssh-agent support';
        }
        if (null === $agent) {
            return 'Failure initializing ssh-agent support';
        }
        $cleanup = static function () use ($ffi, $agent): void {
            try {
                $ffi->libssh2_agent_disconnect($agent);
            } catch (\Throwable) {
            }
            try {
                $ffi->libssh2_agent_free($agent);
            } catch (\Throwable) {
            }
        };
        try {
            if (0 !== (int) $ffi->libssh2_agent_connect($agent)) {
                try {
                    $ffi->libssh2_agent_free($agent);
                } catch (\Throwable) {
                }

                return 'Failure connecting to ssh-agent';
            }
        } catch (\Throwable) {
            try {
                $ffi->libssh2_agent_free($agent);
            } catch (\Throwable) {
            }

            return 'Failure connecting to ssh-agent';
        }
        try {
            if (0 !== (int) $ffi->libssh2_agent_list_identities($agent)) {
                $cleanup();

                return 'Failure requesting identities to ssh-agent';
            }
        } catch (\Throwable) {
            $cleanup();

            return 'Failure requesting identities to ssh-agent';
        }
        $prev = null;
        while (true) {
            try {
                $identity = $ffi->new('struct libssh2_agent_publickey*');
                $rc = (int) $ffi->libssh2_agent_get_identity($agent, \FFI::addr($identity), $prev);
            } catch (\Throwable) {
                $cleanup();

                return 'Failure obtaining identity from ssh-agent support';
            }
            if (1 === $rc) {
                $cleanup();

                return "Couldn't continue authentication";
            }
            if ($rc < 0) {
                $cleanup();

                return 'Failure obtaining identity from ssh-agent support';
            }
            try {
                $authRc = (int) $ffi->libssh2_agent_userauth($agent, $username, $identity);
            } catch (\Throwable) {
                $cleanup();

                return "Couldn't continue authentication";
            }
            if (0 === $authRc) {
                $cleanup();

                return true;
            }
            $prev = $identity;
        }
    }

    /**
     * Probe "none" auth / list allowed methods (PECL ssh2_auth_none; #26678).
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     *
     * @return list<string>|bool  method names, or bool when list is unavailable
     */
    public static function authNone(\FFI\CData $session, string $username)
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        try {
            $ptr = $ffi->libssh2_userauth_list($session, $username, \strlen($username));
        } catch (\Throwable) {
            return false;
        }
        if (null === $ptr) {
            try {
                return 0 !== (int) $ffi->libssh2_userauth_authenticated($session);
            } catch (\Throwable) {
                return false;
            }
        }
        try {
            $methods = \FFI::string($ptr);
        } catch (\Throwable) {
            return false;
        }
        if ('' === $methods) {
            return [];
        }
        $out = [];
        foreach (\explode(',', $methods) as $part) {
            if ('' !== $part) {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * Negotiated KEX/crypt/mac/comp map (PECL ssh2_methods_negotiated; #26679).
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     *
     * @return array{
     *   kex: string,
     *   hostkey: string,
     *   client_to_server: array{crypt: string, mac: string, comp: string, lang: string},
     *   server_to_client: array{crypt: string, mac: string, comp: string, lang: string}
     * }|false
     */
    public static function sessionMethodsNegotiated(\FFI\CData $session)
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        // libssh2.h LIBSSH2_METHOD_* 
        $method = static function (int $type) use ($ffi, $session): string {
            try {
                $ptr = $ffi->libssh2_session_methods($session, $type);
            } catch (\Throwable) {
                return '';
            }
            if (null === $ptr) {
                return '';
            }
            try {
                return \FFI::string($ptr);
            } catch (\Throwable) {
                return '';
            }
        };

        return [
            'kex' => $method(0),
            'hostkey' => $method(1),
            'client_to_server' => [
                'crypt' => $method(2),
                'mac' => $method(4),
                'comp' => $method(6),
                'lang' => $method(8),
            ],
            'server_to_client' => [
                'crypt' => $method(3),
                'mac' => $method(5),
                'comp' => $method(7),
                'lang' => $method(9),
            ],
        ];
    }

    /**
     * Host-key fingerprint after handshake (PECL ssh2_fingerprint / libssh2_hostkey_hash; #26575).
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     *
     * @return string|false
     */
    public static function hostkeyFingerprint(\FFI\CData $session, int $flags)
    {
        require_once __DIR__.'/Ssh2Constants.php';
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $useSha1 = (0 !== ($flags & Ssh2Constants::FINGERPRINT_SHA1));
        $hashType = $useSha1 ? 2 /* LIBSSH2_HOSTKEY_HASH_SHA1 */ : 1 /* MD5 */;
        $digestLen = $useSha1 ? 20 : 16;
        try {
            $ptr = $ffi->libssh2_hostkey_hash($session, $hashType);
        } catch (\Throwable) {
            return false;
        }
        if (null === $ptr) {
            return false;
        }
        // Declare as void* so PHP FFI does not coerce to a NUL-truncated string.
        try {
            $buf = $ffi->new('unsigned char['.$digestLen.']');
            \FFI::memcpy($buf, $ptr, $digestLen);
            $bytes = \FFI::string($buf, $digestLen);
        } catch (\Throwable) {
            return false;
        }
        if ($digestLen !== \strlen($bytes)) {
            return false;
        }
        $allZero = true;
        for ($i = 0; $i < $digestLen; ++$i) {
            if ("\0" !== $bytes[$i]) {
                $allZero = false;
                break;
            }
        }
        if ($allZero) {
            return false;
        }
        if (0 !== ($flags & Ssh2Constants::FINGERPRINT_RAW)) {
            return $bytes;
        }
        $hex = '';
        for ($i = 0; $i < $digestLen; ++$i) {
            $hex .= \sprintf('%02X', \ord($bytes[$i]));
        }

        return $hex;
    }

    /**
     * @param \FFI\CData $session LIBSSH2_SESSION*
     */
    public static function sessionDisconnect(\FFI\CData $session): void
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        try {
            $ffi->libssh2_session_disconnect_ex($session, 11, 'PHPCompiler ssh2_disconnect', '');
        } catch (\Throwable) {
        }
        try {
            $ffi->libssh2_session_free($session);
        } catch (\Throwable) {
        }
    }

    public static function closeFd(int $fd): void
    {
        $libc = self::libc();
        if (null === $libc) {
            return;
        }
        try {
            $libc->close($fd);
        } catch (\Throwable) {
        }
    }

    /**
     * @param \FFI\CData $session LIBSSH2_SESSION*
     *
     * @return \FFI\CData|null LIBSSH2_SFTP*
     */
    public static function sftpInit(\FFI\CData $session)
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $sftp = $ffi->libssh2_sftp_init($session);
        if (null === $sftp) {
            return null;
        }

        return $sftp;
    }

    /**
     * @param \FFI\CData $sftp LIBSSH2_SFTP*
     */
    public static function sftpShutdown(\FFI\CData $sftp): void
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        try {
            $ffi->libssh2_sftp_shutdown($sftp);
        } catch (\Throwable) {
        }
    }

    /**
     * Transfer remote→local via SFTP (PECL ssh2_scp_recv surface; #26510).
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     */
    public static function scpRecv(\FFI\CData $session, string $remoteFile, string $localFile): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $sftp = $ffi->libssh2_sftp_init($session);
        if (null === $sftp) {
            return false;
        }
        // LIBSSH2_FXF_READ = 0x00000001, LIBSSH2_SFTP_OPENFILE = 0
        $handle = $ffi->libssh2_sftp_open_ex($sftp, $remoteFile, \strlen($remoteFile), 0x00000001, 0, 0);
        if (null === $handle) {
            self::sftpShutdown($sftp);

            return false;
        }
        $out = @\fopen($localFile, 'wb');
        if (false === $out) {
            $ffi->libssh2_sftp_close_handle($handle);
            self::sftpShutdown($sftp);

            return false;
        }
        $buf = $ffi->new('char[8192]');
        $ok = true;
        while (true) {
            $nInt = (int) $ffi->libssh2_sftp_read($handle, $buf, 8192);
            if (-37 === $nInt) {
                usleep(1000);
                continue;
            }
            if ($nInt < 0) {
                $ok = false;
                break;
            }
            if (0 === $nInt) {
                break;
            }
            if (false === \fwrite($out, \FFI::string($buf, $nInt))) {
                $ok = false;
                break;
            }
        }
        \fclose($out);
        $ffi->libssh2_sftp_close_handle($handle);
        self::sftpShutdown($sftp);
        if (!$ok) {
            @\unlink($localFile);
        }

        return $ok;
    }

    /**
     * Transfer local→remote via SFTP (PECL ssh2_scp_send surface; #26510).
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     */
    public static function scpSend(\FFI\CData $session, string $localFile, string $remoteFile, int $mode = 0644): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        if (!\is_file($localFile) || !\is_readable($localFile)) {
            return false;
        }
        $sftp = $ffi->libssh2_sftp_init($session);
        if (null === $sftp) {
            return false;
        }
        // LIBSSH2_FXF_WRITE|CREAT|TRUNC = 0x1a, OPENFILE = 0
        $handle = $ffi->libssh2_sftp_open_ex(
            $sftp,
            $remoteFile,
            \strlen($remoteFile),
            0x0000001a,
            $mode,
            0
        );
        if (null === $handle) {
            self::sftpShutdown($sftp);

            return false;
        }
        $in = @\fopen($localFile, 'rb');
        if (false === $in) {
            $ffi->libssh2_sftp_close_handle($handle);
            self::sftpShutdown($sftp);

            return false;
        }
        $ok = true;
        $cbuf = $ffi->new('char[8192]');
        while (!\feof($in)) {
            $data = \fread($in, 8192);
            if (false === $data) {
                $ok = false;
                break;
            }
            if ('' === $data) {
                break;
            }
            $len = \strlen($data);
            \FFI::memcpy($cbuf, $data, $len);
            $off = 0;
            while ($off < $len) {
                $ptr = \FFI::addr($cbuf[$off]);
                $nInt = (int) $ffi->libssh2_sftp_write($handle, $ptr, $len - $off);
                if (-37 === $nInt) {
                    usleep(1000);
                    continue;
                }
                if ($nInt < 0) {
                    $ok = false;
                    break 2;
                }
                $off += $nInt;
            }
        }
        \fclose($in);
        $ffi->libssh2_sftp_close_handle($handle);
        self::sftpShutdown($sftp);

        return $ok;
    }

    /** LIBSSH2_SFTP_STAT — follow symlinks (PECL ssh2_sftp_stat; #26609). */
    public const SFTP_STAT = 0;

    /** LIBSSH2_SFTP_LSTAT — do not follow symlinks (PECL ssh2_sftp_lstat; #26609). */
    public const SFTP_LSTAT = 1;

    /** LIBSSH2_SFTP_SETSTAT — set attributes (PECL ssh2_sftp_chmod; #26611). */
    public const SFTP_SETSTAT = 2;

    /** LIBSSH2_SFTP_SYMLINK — create symlink (PECL ssh2_sftp_symlink; #26662). */
    public const SFTP_SYMLINK = 0;

    /** LIBSSH2_SFTP_READLINK — resolve symlink (PECL ssh2_sftp_readlink; #26662). */
    public const SFTP_READLINK = 1;

    /** LIBSSH2_SFTP_REALPATH — canonicalize path (PECL ssh2_sftp_realpath; #26661). */
    public const SFTP_REALPATH = 2;

    private const SFTP_ATTR_SIZE = 0x00000001;

    private const SFTP_ATTR_UIDGID = 0x00000002;

    private const SFTP_ATTR_PERMISSIONS = 0x00000004;

    private const SFTP_ATTR_ACMODTIME = 0x00000008;

    /**
     * Stat remote path via libssh2 (PECL ssh2_sftp_stat / lstat; #26609).
     *
     * @param \FFI\CData $sftp LIBSSH2_SFTP*
     *
     * @return array<int|string, int>|false PECL dual-key layout (index + name)
     */
    public static function sftpStat(\FFI\CData $sftp, string $path, int $statType)
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $attrs = $ffi->new('LIBSSH2_SFTP_ATTRIBUTES');
        $rc = $ffi->libssh2_sftp_stat_ex($sftp, $path, \strlen($path), $statType, \FFI::addr($attrs));
        if (0 !== $rc) {
            return false;
        }
        $out = [];
        $flags = (int) $attrs->flags;
        if ($flags & self::SFTP_ATTR_SIZE) {
            $size = (int) $attrs->filesize;
            $out[7] = $size;
            $out['size'] = $size;
        }
        if ($flags & self::SFTP_ATTR_UIDGID) {
            $uid = (int) $attrs->uid;
            $gid = (int) $attrs->gid;
            $out[4] = $uid;
            $out['uid'] = $uid;
            $out[5] = $gid;
            $out['gid'] = $gid;
        }
        if ($flags & self::SFTP_ATTR_PERMISSIONS) {
            $mode = (int) $attrs->permissions;
            $out[2] = $mode;
            $out['mode'] = $mode;
        }
        if ($flags & self::SFTP_ATTR_ACMODTIME) {
            $atime = (int) $attrs->atime;
            $mtime = (int) $attrs->mtime;
            $out[8] = $atime;
            $out['atime'] = $atime;
            $out[9] = $mtime;
            $out['mtime'] = $mtime;
        }

        return $out;
    }

    /**
     * Create remote directory (PECL ssh2_sftp_mkdir; #26610).
     *
     * @param \FFI\CData $sftp LIBSSH2_SFTP*
     */
    public static function sftpMkdir(\FFI\CData $sftp, string $path, int $mode = 0777, bool $recursive = false): bool
    {
        $ffi = self::ffi();
        if (null === $ffi || '' === $path) {
            return false;
        }
        if ($recursive) {
            $len = \strlen($path);
            $offset = 0;
            while (false !== ($pos = \strpos($path, '/', $offset + 1))) {
                if ($pos + 1 === $len) {
                    break;
                }
                $ffi->libssh2_sftp_mkdir_ex($sftp, $path, $pos, $mode);
                $offset = $pos;
            }
        }

        return 0 === (int) $ffi->libssh2_sftp_mkdir_ex($sftp, $path, \strlen($path), $mode);
    }

    /**
     * Remove remote directory (PECL ssh2_sftp_rmdir; #26610).
     *
     * @param \FFI\CData $sftp LIBSSH2_SFTP*
     */
    public static function sftpRmdir(\FFI\CData $sftp, string $path): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        return 0 === (int) $ffi->libssh2_sftp_rmdir_ex($sftp, $path, \strlen($path));
    }

    /**
     * Unlink remote file (PECL ssh2_sftp_unlink; #26610).
     *
     * @param \FFI\CData $sftp LIBSSH2_SFTP*
     */
    public static function sftpUnlink(\FFI\CData $sftp, string $path): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        return 0 === (int) $ffi->libssh2_sftp_unlink_ex($sftp, $path, \strlen($path));
    }

    /**
     * Rename remote path (PECL ssh2_sftp_rename; #26611).
     *
     * @param \FFI\CData $sftp LIBSSH2_SFTP*
     */
    public static function sftpRename(\FFI\CData $sftp, string $from, string $to): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        // LIBSSH2_SFTP_RENAME_OVERWRITE|ATOMIC|NATIVE — PECL default flags
        $flags = 0x00000001 | 0x00000002 | 0x00000004;

        return 0 === (int) $ffi->libssh2_sftp_rename_ex(
            $sftp,
            $from,
            \strlen($from),
            $to,
            \strlen($to),
            $flags
        );
    }

    /**
     * Set remote file mode (PECL ssh2_sftp_chmod; #26611).
     *
     * @param \FFI\CData $sftp LIBSSH2_SFTP*
     */
    public static function sftpChmod(\FFI\CData $sftp, string $filename, int $mode): bool
    {
        $ffi = self::ffi();
        if (null === $ffi || '' === $filename) {
            return false;
        }
        $attrs = $ffi->new('LIBSSH2_SFTP_ATTRIBUTES');
        $attrs->permissions = $mode;
        $attrs->flags = self::SFTP_ATTR_PERMISSIONS;

        return 0 === (int) $ffi->libssh2_sftp_stat_ex(
            $sftp,
            $filename,
            \strlen($filename),
            self::SFTP_SETSTAT,
            \FFI::addr($attrs)
        );
    }

    /**
     * Canonicalize remote path (PECL ssh2_sftp_realpath; #26661).
     *
     * @param \FFI\CData $sftp LIBSSH2_SFTP*
     *
     * @return string|false
     */
    public static function sftpRealpath(\FFI\CData $sftp, string $path)
    {
        return self::sftpSymlinkExRead($sftp, $path, self::SFTP_REALPATH);
    }

    /**
     * Filesystem statistics via libssh2_sftp_statvfs (#26740).
     *
     * Associative keys match the PECL-shaped surface documented on the issue
     * (bsize/frsize/… without the C `f_` prefix).
     *
     * @param \FFI\CData $sftp LIBSSH2_SFTP*
     *
     * @return array<string, int>|false
     */
    public static function sftpStatvfs(\FFI\CData $sftp, string $path)
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $st = $ffi->new('LIBSSH2_SFTP_STATVFS');
        $rc = (int) $ffi->libssh2_sftp_statvfs($sftp, $path, \strlen($path), \FFI::addr($st));
        if (0 !== $rc) {
            return false;
        }

        return [
            'bsize' => (int) $st->f_bsize,
            'frsize' => (int) $st->f_frsize,
            'blocks' => (int) $st->f_blocks,
            'bfree' => (int) $st->f_bfree,
            'bavail' => (int) $st->f_bavail,
            'files' => (int) $st->f_files,
            'ffree' => (int) $st->f_ffree,
            'favail' => (int) $st->f_favail,
            'fsid' => (int) $st->f_fsid,
            'flag' => (int) $st->f_flag,
            'namemax' => (int) $st->f_namemax,
        ];
    }

    /**
     * Resolve remote symlink target (PECL ssh2_sftp_readlink; #26662).
     *
     * @param \FFI\CData $sftp LIBSSH2_SFTP*
     *
     * @return string|false
     */
    public static function sftpReadlink(\FFI\CData $sftp, string $path)
    {
        return self::sftpSymlinkExRead($sftp, $path, self::SFTP_READLINK);
    }

    /**
     * Create remote symlink (PECL ssh2_sftp_symlink; #26662).
     *
     * @param \FFI\CData $sftp LIBSSH2_SFTP*
     */
    public static function sftpSymlink(\FFI\CData $sftp, string $target, string $link): bool
    {
        $ffi = self::ffi();
        if (null === $ffi || '' === $target || '' === $link) {
            return false;
        }

        return 0 === (int) $ffi->libssh2_sftp_symlink_ex(
            $sftp,
            $target,
            \strlen($target),
            $link,
            \strlen($link),
            self::SFTP_SYMLINK
        );
    }

    /**
     * READLINK / REALPATH via libssh2_sftp_symlink_ex (PECL 8192-byte buffer).
     *
     * @param \FFI\CData $sftp LIBSSH2_SFTP*
     *
     * @return string|false
     */
    private static function sftpSymlinkExRead(\FFI\CData $sftp, string $path, int $linkType)
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $buf = $ffi->new('char[8192]');
        $n = (int) $ffi->libssh2_sftp_symlink_ex(
            $sftp,
            $path,
            \strlen($path),
            $buf,
            8192,
            $linkType
        );
        if ($n < 0) {
            return false;
        }

        return \FFI::string($buf, $n);
    }

    /**
     * Open session channel, exec command, drain stdout (PECL ssh2_exec; #26576).
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     *
     * @return string|false
     */
    public static function channelExecDrain(\FFI\CData $session, string $command)
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $sessionType = 'session';
        $channel = $ffi->libssh2_channel_open_ex(
            $session,
            $sessionType,
            \strlen($sessionType),
            2 * 1024 * 1024,
            32768,
            null,
            0
        );
        if (null === $channel) {
            return false;
        }
        $request = 'exec';
        $rc = $ffi->libssh2_channel_process_startup(
            $channel,
            $request,
            \strlen($request),
            $command,
            \strlen($command)
        );
        if (0 !== $rc) {
            try {
                $ffi->libssh2_channel_free($channel);
            } catch (\Throwable) {
            }

            return false;
        }
        $out = '';
        $buf = $ffi->new('char[8192]');
        $guard = 0;
        while ($guard++ < 100000) {
            $n = $ffi->libssh2_channel_read_ex($channel, 0, $buf, 8192);
            if ($n > 0) {
                $out .= \FFI::string($buf, (int) $n);
                continue;
            }
            if (1 === (int) $ffi->libssh2_channel_eof($channel)) {
                break;
            }
            if (0 === $n) {
                if (1 === (int) $ffi->libssh2_channel_eof($channel)) {
                    break;
                }
                usleep(1000);
                continue;
            }
            if ($n < 0 && $n !== -37) {
                break;
            }
            usleep(1000);
        }
        try {
            $ffi->libssh2_channel_send_eof($channel);
        } catch (\Throwable) {
        }
        try {
            $ffi->libssh2_channel_close($channel);
            $ffi->libssh2_channel_wait_closed($channel);
        } catch (\Throwable) {
        }
        try {
            $ffi->libssh2_channel_free($channel);
        } catch (\Throwable) {
        }

        return $out;
    }

    /**
     * Open interactive shell channel (PECL ssh2_shell; #26663).
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     *
     * @return \FFI\CData|null LIBSSH2_CHANNEL*
     */
    public static function channelShellOpen(
        \FFI\CData $session,
        string $term,
        int $width,
        int $height,
        int $unitChars
    ) {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $ffi->libssh2_session_set_blocking($session, 1);
        $sessionType = 'session';
        $channel = $ffi->libssh2_channel_open_ex(
            $session,
            $sessionType,
            \strlen($sessionType),
            2 * 1024 * 1024,
            32768,
            null,
            0
        );
        if (null === $channel) {
            return null;
        }
        if ($unitChars) {
            $rc = (int) $ffi->libssh2_channel_request_pty_ex(
                $channel,
                $term,
                \strlen($term),
                null,
                0,
                $width,
                $height,
                0,
                0
            );
        } else {
            $rc = (int) $ffi->libssh2_channel_request_pty_ex(
                $channel,
                $term,
                \strlen($term),
                null,
                0,
                0,
                0,
                $width,
                $height
            );
        }
        if (0 !== $rc) {
            try {
                $ffi->libssh2_channel_free($channel);
            } catch (\Throwable) {
            }

            return null;
        }
        $request = 'shell';
        $rc = (int) $ffi->libssh2_channel_process_startup(
            $channel,
            $request,
            \strlen($request),
            null,
            0
        );
        if (0 !== $rc) {
            try {
                $ffi->libssh2_channel_free($channel);
            } catch (\Throwable) {
            }

            return null;
        }

        return $channel;
    }

    /**
     * Free a shell/exec channel (PECL stream destructor; #26663).
     *
     * @param \FFI\CData $channel LIBSSH2_CHANNEL*
     */
    public static function channelFree(\FFI\CData $channel): void
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        try {
            $ffi->libssh2_channel_close($channel);
        } catch (\Throwable) {
        }
        try {
            $ffi->libssh2_channel_free($channel);
        } catch (\Throwable) {
        }
    }

    /**
     * Send EOF on a channel (PECL ssh2_send_eof; #26736).
     *
     * @param \FFI\CData $channel LIBSSH2_CHANNEL*
     */
    public static function channelSendEof(\FFI\CData $channel): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        try {
            return 0 === (int) $ffi->libssh2_channel_send_eof($channel);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Send a signal to a remote process on a channel (PECL ssh2_send_signal; #26736).
     *
     * @param \FFI\CData $channel LIBSSH2_CHANNEL*
     */
    public static function channelSendSignal(\FFI\CData $channel, string $signal): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        try {
            // libssh2_channel_signal_ex(channel, signalname, signalname_len)
            $rc = (int) $ffi->libssh2_channel_signal_ex($channel, $signal, \strlen($signal));

            return $rc >= 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Configure session keepalive (PECL ssh2_keepalive_config; #26737).
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     */
    public static function sessionKeepaliveConfig(\FFI\CData $session, bool $wantReply, int $interval): void
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        try {
            $ffi->libssh2_keepalive_config($session, $wantReply ? 1 : 0, $interval);
        } catch (\Throwable) {
        }
    }

    /**
     * Send keepalive if needed (PECL ssh2_keepalive_send; #26737).
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     *
     * @return int|false seconds until next keepalive needed, or false on error
     */
    public static function sessionKeepaliveSend(\FFI\CData $session)
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        try {
            $secondsToNext = \FFI::new('int');
            $rc = (int) $ffi->libssh2_keepalive_send($session, \FFI::addr($secondsToNext));
            if (0 !== $rc) {
                return false;
            }

            return (int) $secondsToNext->cdata;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Set session I/O timeout in milliseconds (PECL ssh2_set_timeout; #26737).
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     */
    public static function sessionSetTimeout(\FFI\CData $session, int $timeoutMs): void
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        try {
            $ffi->libssh2_session_set_timeout($session, $timeoutMs);
        } catch (\Throwable) {
        }
    }

    /**
     * Request PTY size change on a channel (PECL ssh2_shell_resize; #26737).
     *
     * @param \FFI\CData $channel LIBSSH2_CHANNEL*
     */
    public static function channelRequestPtySize(
        \FFI\CData $channel,
        int $width,
        int $height,
        int $widthPx,
        int $heightPx
    ): bool {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        try {
            $ffi->libssh2_channel_request_pty_size_ex($channel, $width, $height, $widthPx, $heightPx);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Multiplex channel/listener readiness (PECL ssh2_poll / libssh2_poll; #26735).
     *
     * @param list<array{type: int, native: \FFI\CData, events: int}> $entries
     * @param-out list<int> $reventsOut
     *
     * @return int|false number of descriptors with revents, or false on FFI failure
     */
    public static function poll(array $entries, int $timeoutSec, ?array &$reventsOut = null)
    {
        $reventsOut = [];
        $n = \count($entries);
        if (0 === $n) {
            return 0;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            for ($i = 0; $i < $n; ++$i) {
                $reventsOut[] = 0;
            }

            return 0;
        }
        try {
            $fds = $ffi->new('LIBSSH2_POLLFD['.$n.']');
            for ($i = 0; $i < $n; ++$i) {
                $fds[$i]->type = $entries[$i]['type'];
                if (2 === $entries[$i]['type']) { // LIBSSH2_POLLFD_CHANNEL
                    $fds[$i]->fd->channel = $entries[$i]['native'];
                } else {
                    $fds[$i]->fd->listener = $entries[$i]['native'];
                }
                $fds[$i]->events = $entries[$i]['events'];
                $fds[$i]->revents = 0;
            }
            $ready = (int) $ffi->libssh2_poll($fds, $n, $timeoutSec * 1000);
            for ($i = 0; $i < $n; ++$i) {
                $reventsOut[] = (int) $fds[$i]->revents;
            }

            return $ready;
        } catch (\Throwable) {
            for ($i = 0; $i < $n; ++$i) {
                $reventsOut[] = 0;
            }

            return 0;
        }
    }

    /**
     * Open direct-tcpip tunnel channel (PECL ssh2_tunnel; #26677).
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     *
     * @return \FFI\CData|null LIBSSH2_CHANNEL*
     */
    public static function channelDirectTcpip(\FFI\CData $session, string $host, int $port)
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $ffi->libssh2_session_set_blocking($session, 1);
        // PECL php_ssh2_direct_tcpip → libssh2_channel_direct_tcpip (shost=127.0.0.1, sport=22).
        $shost = '127.0.0.1';
        try {
            $channel = $ffi->libssh2_channel_direct_tcpip_ex(
                $session,
                $host,
                $port,
                $shost,
                22
            );
        } catch (\Throwable) {
            return null;
        }
        if (null === $channel) {
            return null;
        }

        return $channel;
    }

    /**
     * Bind a remote forward listener (PECL ssh2_forward_listen; #26715).
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     *
     * @return \FFI\CData|null LIBSSH2_LISTENER*
     */
    public static function channelForwardListen(
        \FFI\CData $session,
        int $port,
        ?string $host,
        int $maxConnections
    ) {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $ffi->libssh2_session_set_blocking($session, 1);
        try {
            $listener = $ffi->libssh2_channel_forward_listen_ex(
                $session,
                $host,
                $port,
                null,
                $maxConnections
            );
        } catch (\Throwable) {
            return null;
        }

        return $listener;
    }

    /**
     * Accept a connection on a remote forward listener (PECL ssh2_forward_accept; #26715).
     *
     * @param \FFI\CData $listener LIBSSH2_LISTENER*
     *
     * @return \FFI\CData|null LIBSSH2_CHANNEL*
     */
    public static function channelForwardAccept(\FFI\CData $listener)
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        try {
            $channel = $ffi->libssh2_channel_forward_accept($listener);
        } catch (\Throwable) {
            return null;
        }

        return $channel;
    }

    /**
     * Cancel a remote forward listener (PECL listener dtor; #26715).
     *
     * @param \FFI\CData $listener LIBSSH2_LISTENER*
     */
    public static function channelForwardCancel(\FFI\CData $listener): void
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        try {
            $ffi->libssh2_channel_forward_cancel($listener);
        } catch (\Throwable) {
        }
    }

    /**
     * Init publickey subsystem (PECL ssh2_publickey_init; #26717).
     *
     * @param \FFI\CData $session LIBSSH2_SESSION*
     *
     * @return \FFI\CData|null LIBSSH2_PUBLICKEY*
     */
    public static function publickeyInit(\FFI\CData $session)
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        try {
            $pkey = $ffi->libssh2_publickey_init($session);
        } catch (\Throwable) {
            return null;
        }

        return $pkey;
    }

    /**
     * Add a public key via subsystem (PECL ssh2_publickey_add; #26717).
     *
     * @param \FFI\CData $pkey LIBSSH2_PUBLICKEY*
     */
    public static function publickeyAdd(
        \FFI\CData $pkey,
        string $algo,
        string $blob,
        bool $overwrite
    ): bool {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        try {
            $rc = (int) $ffi->libssh2_publickey_add_ex(
                $pkey,
                $algo,
                \strlen($algo),
                $blob,
                \strlen($blob),
                $overwrite ? 1 : 0,
                0,
                null
            );
        } catch (\Throwable) {
            return false;
        }

        return 0 === $rc;
    }

    /**
     * Remove a public key via subsystem (PECL ssh2_publickey_remove; #26717).
     *
     * @param \FFI\CData $pkey LIBSSH2_PUBLICKEY*
     */
    public static function publickeyRemove(\FFI\CData $pkey, string $algo, string $blob): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        try {
            $rc = (int) $ffi->libssh2_publickey_remove_ex(
                $pkey,
                $algo,
                \strlen($algo),
                $blob,
                \strlen($blob)
            );
        } catch (\Throwable) {
            return false;
        }

        return 0 === $rc;
    }

    /**
     * List installed public keys (PECL ssh2_publickey_list; #26717).
     *
     * @param \FFI\CData $pkey LIBSSH2_PUBLICKEY*
     *
     * @return list<array{name: string, blob: string, attrs: array<string, string>}>|false
     */
    public static function publickeyList(\FFI\CData $pkey)
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        try {
            $numKeys = $ffi->new('unsigned long');
            $listPtr = $ffi->new('libssh2_publickey_list*');
            $rc = (int) $ffi->libssh2_publickey_list_fetch(
                $pkey,
                \FFI::addr($numKeys),
                \FFI::addr($listPtr)
            );
        } catch (\Throwable) {
            return false;
        }
        if (0 !== $rc || null === $listPtr) {
            return false;
        }
        $n = (int) $numKeys->cdata;
        $out = [];
        try {
            for ($i = 0; $i < $n; ++$i) {
                $entry = $listPtr[$i];
                $nameLen = (int) $entry->name_len;
                $blobLen = (int) $entry->blob_len;
                $name = $nameLen > 0 && null !== $entry->name
                    ? \FFI::string($entry->name, $nameLen)
                    : '';
                $blob = $blobLen > 0 && null !== $entry->blob
                    ? \FFI::string($entry->blob, $blobLen)
                    : '';
                $attrs = [];
                $numAttrs = (int) $entry->num_attrs;
                if ($numAttrs > 0 && null !== $entry->attrs) {
                    for ($j = 0; $j < $numAttrs; ++$j) {
                        $attr = $entry->attrs[$j];
                        $aNameLen = (int) $attr->name_len;
                        $aValLen = (int) $attr->value_len;
                        if ($aNameLen <= 0 || null === $attr->name) {
                            continue;
                        }
                        $aName = \FFI::string($attr->name, $aNameLen);
                        $aVal = ($aValLen > 0 && null !== $attr->value)
                            ? \FFI::string($attr->value, $aValLen)
                            : '';
                        $attrs[$aName] = $aVal;
                    }
                }
                $out[] = [
                    'name' => $name,
                    'blob' => $blob,
                    'attrs' => $attrs,
                ];
            }
        } catch (\Throwable) {
            try {
                $ffi->libssh2_publickey_list_free($pkey, $listPtr);
            } catch (\Throwable) {
            }

            return false;
        }
        try {
            $ffi->libssh2_publickey_list_free($pkey, $listPtr);
        } catch (\Throwable) {
        }

        return $out;
    }

    /**
     * Shutdown publickey subsystem (PECL pkey dtor; #26717).
     *
     * @param \FFI\CData $pkey LIBSSH2_PUBLICKEY*
     */
    public static function publickeyShutdown(\FFI\CData $pkey): void
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        try {
            $ffi->libssh2_publickey_shutdown($pkey);
        } catch (\Throwable) {
        }
    }

    /**
     * @return \FFI|null
     */
    private static function ffi()
    {
        if (false !== self::$ffi) {
            return self::$ffi;
        }
        if (!self::ffiEnabled() || !\extension_loaded('ffi')) {
            self::$ffi = null;

            return null;
        }
        $cdef = <<<'C'
typedef struct _LIBSSH2_SESSION LIBSSH2_SESSION;
int libssh2_init(int flags);
void libssh2_exit(void);
LIBSSH2_SESSION *libssh2_session_init_ex(void *(*alloc)(size_t), void (*free)(void*), void *(*realloc)(void*, size_t), void *abstract);
int libssh2_session_handshake(LIBSSH2_SESSION *session, int sock);
void libssh2_session_set_blocking(LIBSSH2_SESSION *session, int blocking);
int libssh2_userauth_password_ex(LIBSSH2_SESSION *session, const char *username, unsigned int username_len, const char *password, unsigned int password_len, void *passwd_change_cb);
void *libssh2_hostkey_hash(LIBSSH2_SESSION *session, int hash_type);
int libssh2_userauth_publickey_fromfile_ex(LIBSSH2_SESSION *session, const char *username, unsigned int username_len, const char *publickey, const char *privatekey, const char *passphrase);
int libssh2_userauth_publickey_frommemory(LIBSSH2_SESSION *session, const char *username, size_t username_len, const char *publickeyfiledata, size_t publickeyfiledata_len, const char *privatekeyfiledata, size_t privatekeyfiledata_len, const char *passphrase);
int libssh2_session_last_error(LIBSSH2_SESSION *session, char **errmsg, int *errmsg_len, int want_buf);
char *libssh2_userauth_list(LIBSSH2_SESSION *session, const char *username, unsigned int username_len);
int libssh2_userauth_authenticated(LIBSSH2_SESSION *session);
const char *libssh2_session_methods(LIBSSH2_SESSION *session, int method_type);
int libssh2_session_disconnect_ex(LIBSSH2_SESSION *session, int reason, const char *description, const char *lang);
int libssh2_session_free(LIBSSH2_SESSION *session);
typedef struct _LIBSSH2_SFTP LIBSSH2_SFTP;
typedef struct _LIBSSH2_SFTP_HANDLE LIBSSH2_SFTP_HANDLE;
LIBSSH2_SFTP *libssh2_sftp_init(LIBSSH2_SESSION *session);
int libssh2_sftp_shutdown(LIBSSH2_SFTP *sftp);
LIBSSH2_SFTP_HANDLE *libssh2_sftp_open_ex(LIBSSH2_SFTP *sftp, const char *filename, unsigned int filename_len, unsigned long flags, long mode, int open_type);
ssize_t libssh2_sftp_read(LIBSSH2_SFTP_HANDLE *handle, char *buffer, size_t buffer_maxlen);
ssize_t libssh2_sftp_write(LIBSSH2_SFTP_HANDLE *handle, const char *buffer, size_t count);
int libssh2_sftp_close_handle(LIBSSH2_SFTP_HANDLE *handle);
typedef struct _LIBSSH2_SFTP_ATTRIBUTES {
    unsigned long flags;
    unsigned long long filesize;
    unsigned long uid;
    unsigned long gid;
    unsigned long permissions;
    unsigned long atime;
    unsigned long mtime;
} LIBSSH2_SFTP_ATTRIBUTES;
int libssh2_sftp_stat_ex(LIBSSH2_SFTP *sftp, const char *path, unsigned int path_len, int stat_type, LIBSSH2_SFTP_ATTRIBUTES *attrs);
int libssh2_sftp_mkdir_ex(LIBSSH2_SFTP *sftp, const char *path, size_t path_len, long mode);
int libssh2_sftp_rmdir_ex(LIBSSH2_SFTP *sftp, const char *path, size_t path_len);
int libssh2_sftp_unlink_ex(LIBSSH2_SFTP *sftp, const char *filename, size_t filename_len);
int libssh2_sftp_rename_ex(LIBSSH2_SFTP *sftp, const char *source_filename, unsigned int source_filename_len, const char *dest_filename, unsigned int dest_filename_len, long flags);
int libssh2_sftp_symlink_ex(LIBSSH2_SFTP *sftp, const char *path, unsigned int path_len, char *target, unsigned int target_len, int link_type);
typedef struct _LIBSSH2_SFTP_STATVFS {
    unsigned long long f_bsize;
    unsigned long long f_frsize;
    unsigned long long f_blocks;
    unsigned long long f_bfree;
    unsigned long long f_bavail;
    unsigned long long f_files;
    unsigned long long f_ffree;
    unsigned long long f_favail;
    unsigned long long f_fsid;
    unsigned long long f_flag;
    unsigned long long f_namemax;
} LIBSSH2_SFTP_STATVFS;
int libssh2_sftp_statvfs(LIBSSH2_SFTP *sftp, const char *path, size_t path_len, LIBSSH2_SFTP_STATVFS *st);
typedef struct _LIBSSH2_CHANNEL LIBSSH2_CHANNEL;
typedef struct _LIBSSH2_LISTENER LIBSSH2_LISTENER;
typedef struct _LIBSSH2_AGENT LIBSSH2_AGENT;
typedef struct _LIBSSH2_POLLFD {
    unsigned char type;
    union {
        int socket;
        LIBSSH2_CHANNEL *channel;
        LIBSSH2_LISTENER *listener;
    } fd;
    unsigned long events;
    unsigned long revents;
} LIBSSH2_POLLFD;
int libssh2_poll(LIBSSH2_POLLFD *fds, unsigned int nfds, long timeout);
struct libssh2_agent_publickey {
    unsigned int magic;
    void *node;
    unsigned char *blob;
    size_t blob_len;
    char *comment;
};
LIBSSH2_CHANNEL *libssh2_channel_open_ex(LIBSSH2_SESSION *session, const char *channel_type, unsigned int channel_type_len, unsigned int window_size, unsigned int packet_size, const char *message, unsigned int message_len);
int libssh2_channel_process_startup(LIBSSH2_CHANNEL *channel, const char *request, size_t request_len, const char *message, size_t message_len);
int libssh2_channel_request_pty_ex(LIBSSH2_CHANNEL *channel, const char *term, unsigned int term_len, const char *modes, unsigned int modes_len, int width, int height, int width_px, int height_px);
ssize_t libssh2_channel_read_ex(LIBSSH2_CHANNEL *channel, int stream_id, char *buf, size_t buflen);
int libssh2_channel_eof(LIBSSH2_CHANNEL *channel);
int libssh2_channel_send_eof(LIBSSH2_CHANNEL *channel);
int libssh2_channel_signal_ex(LIBSSH2_CHANNEL *channel, const char *signame, size_t signame_len);
int libssh2_channel_request_pty_size_ex(LIBSSH2_CHANNEL *channel, int width, int height, int width_px, int height_px);
int libssh2_channel_close(LIBSSH2_CHANNEL *channel);
int libssh2_channel_wait_closed(LIBSSH2_CHANNEL *channel);
int libssh2_channel_free(LIBSSH2_CHANNEL *channel);
LIBSSH2_CHANNEL *libssh2_channel_direct_tcpip_ex(LIBSSH2_SESSION *session, const char *host, int port, const char *shost, int sport);
int libssh2_userauth_hostbased_fromfile_ex(LIBSSH2_SESSION *session, const char *username, unsigned int username_len, const char *pubkeyfile, const char *privkeyfile, const char *passphrase, const char *hostname, unsigned int hostname_len, const char *local_username, unsigned int local_username_len);
LIBSSH2_LISTENER *libssh2_channel_forward_listen_ex(LIBSSH2_SESSION *session, const char *host, int port, int *bound_port, int queue_maxsize);
int libssh2_channel_forward_cancel(LIBSSH2_LISTENER *listener);
LIBSSH2_CHANNEL *libssh2_channel_forward_accept(LIBSSH2_LISTENER *listener);
void libssh2_keepalive_config(LIBSSH2_SESSION *session, int want_reply, unsigned int interval);
int libssh2_keepalive_send(LIBSSH2_SESSION *session, int *seconds_to_next);
void libssh2_session_set_timeout(LIBSSH2_SESSION *session, long timeout);
LIBSSH2_AGENT *libssh2_agent_init(LIBSSH2_SESSION *session);
int libssh2_agent_connect(LIBSSH2_AGENT *agent);
int libssh2_agent_list_identities(LIBSSH2_AGENT *agent);
int libssh2_agent_get_identity(LIBSSH2_AGENT *agent, struct libssh2_agent_publickey **store, struct libssh2_agent_publickey *prev);
int libssh2_agent_userauth(LIBSSH2_AGENT *agent, const char *username, struct libssh2_agent_publickey *identity);
int libssh2_agent_disconnect(LIBSSH2_AGENT *agent);
void libssh2_agent_free(LIBSSH2_AGENT *agent);
typedef struct _LIBSSH2_PUBLICKEY LIBSSH2_PUBLICKEY;
typedef struct _libssh2_publickey_attribute {
    const char *name;
    unsigned long name_len;
    const char *value;
    unsigned long value_len;
    char mandatory;
} libssh2_publickey_attribute;
typedef struct _libssh2_publickey_list {
    unsigned char *packet;
    const unsigned char *name;
    unsigned long name_len;
    const unsigned char *blob;
    unsigned long blob_len;
    unsigned long num_attrs;
    libssh2_publickey_attribute *attrs;
} libssh2_publickey_list;
LIBSSH2_PUBLICKEY *libssh2_publickey_init(LIBSSH2_SESSION *session);
int libssh2_publickey_add_ex(LIBSSH2_PUBLICKEY *pkey, const unsigned char *name, unsigned long name_len, const unsigned char *blob, unsigned long blob_len, char overwrite, unsigned long num_attrs, const libssh2_publickey_attribute *attrs);
int libssh2_publickey_remove_ex(LIBSSH2_PUBLICKEY *pkey, const unsigned char *name, unsigned long name_len, const unsigned char *blob, unsigned long blob_len);
int libssh2_publickey_list_fetch(LIBSSH2_PUBLICKEY *pkey, unsigned long *num_keys, libssh2_publickey_list **pkey_list);
void libssh2_publickey_list_free(LIBSSH2_PUBLICKEY *pkey, libssh2_publickey_list *pkey_list);
int libssh2_publickey_shutdown(LIBSSH2_PUBLICKEY *pkey);
C;
        foreach (['libssh2.so.1', 'libssh2.so', '/usr/lib/x86_64-linux-gnu/libssh2.so.1'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }
        self::$ffi = null;

        return null;
    }

    /**
     * @return \FFI|null
     */
    private static function libc()
    {
        if (false !== self::$libc) {
            return self::$libc;
        }
        if (!self::ffiEnabled() || !\extension_loaded('ffi')) {
            self::$libc = null;

            return null;
        }
        $cdef = <<<'C'
typedef unsigned short sa_family_t;
typedef uint16_t in_port_t;
typedef uint32_t in_addr_t;
struct in_addr { in_addr_t s_addr; };
struct sockaddr_in {
    sa_family_t sin_family;
    in_port_t sin_port;
    struct in_addr sin_addr;
    char sin_zero[8];
};
int socket(int domain, int type, int protocol);
int connect(int sockfd, const void *addr, unsigned int addrlen);
int close(int fd);
int fcntl(int fd, int cmd, ...);
unsigned int htons(unsigned int hostshort);
in_addr_t inet_addr(const char *cp);
struct hostent {
    char *h_name;
    char **h_aliases;
    int h_addrtype;
    int h_length;
    char **h_addr_list;
};
struct hostent *gethostbyname(const char *name);
void *memcpy(void *dest, const void *src, size_t n);
C;
        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$libc = \FFI::cdef($cdef, $lib);

                return self::$libc;
            } catch (\Throwable) {
            }
        }
        self::$libc = null;

        return null;
    }

    private static function ensureLibssh2Init(\FFI $ffi): void
    {
        if (self::$libssh2Inited) {
            return;
        }
        $ffi->libssh2_init(0);
        self::$libssh2Inited = true;
    }

    private static function tcpConnectFd(string $host, int $port, float $timeoutSec): ?int
    {
        unset($timeoutSec); // best-effort blocking connect; PECL uses php_network timeouts.
        $libc = self::libc();
        if (null === $libc) {
            // Fallback: PHP sockets → export stream FD via fileno when available.
            return self::tcpConnectFdViaPhpSockets($host, $port);
        }
        $AF_INET = 2;
        $SOCK_STREAM = 1;
        $fd = $libc->socket($AF_INET, $SOCK_STREAM, 0);
        if ($fd < 0) {
            return null;
        }
        $addr = self::resolveIpv4($libc, $host);
        if (null === $addr) {
            self::closeFd($fd);

            return null;
        }
        $sa = $libc->new('struct sockaddr_in');
        $sa->sin_family = $AF_INET;
        $sa->sin_port = $libc->htons($port & 0xffff);
        $sa->sin_addr->s_addr = $addr;
        $rc = $libc->connect($fd, \FFI::addr($sa), \FFI::sizeof($sa));
        if (0 !== $rc) {
            self::closeFd($fd);

            return null;
        }

        return $fd;
    }

    /**
     * @param \FFI $libc
     */
    private static function resolveIpv4(\FFI $libc, string $host): ?int
    {
        if (\filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            $packed = $libc->inet_addr($host);
            // (in_addr_t)-1 on error
            if (0xffffffff === ($packed & 0xffffffff) && '255.255.255.255' !== $host) {
                return null;
            }

            return $packed;
        }
        $he = $libc->gethostbyname($host);
        if (null === $he || null === $he->h_addr_list || null === $he->h_addr_list[0]) {
            return null;
        }
        $buf = $libc->new('unsigned int');
        $libc->memcpy(\FFI::addr($buf), $he->h_addr_list[0], 4);

        return $buf->cdata;
    }

    private static function tcpConnectFdViaPhpSockets(string $host, int $port): ?int
    {
        if (!\extension_loaded('sockets')) {
            return null;
        }
        $sock = @\socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        if (false === $sock) {
            return null;
        }
        $ok = @\socket_connect($sock, $host, $port);
        if (!$ok) {
            @\socket_close($sock);

            return null;
        }
        // PHP 8 Socket object — pull underlying FD via stream export + FFI fileno when possible.
        $stream = @\socket_export_stream($sock);
        if (false === $stream) {
            @\socket_close($sock);

            return null;
        }
        $meta = \stream_get_meta_data($stream);
        // Keep $sock alive for the session lifetime by leaking the Socket into a static bag
        // keyed by fd — closed in closeFdViaPhp when we only have libc.
        unset($meta);
        // Without fileno, cannot hand FD to libssh2 — fail closed.
        @\socket_close($sock);

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }
}
