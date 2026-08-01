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
int libssh2_session_last_error(LIBSSH2_SESSION *session, char **errmsg, int *errmsg_len, int want_buf);
char *libssh2_userauth_list(LIBSSH2_SESSION *session, const char *username, unsigned int username_len);
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
typedef struct _LIBSSH2_CHANNEL LIBSSH2_CHANNEL;
LIBSSH2_CHANNEL *libssh2_channel_open_ex(LIBSSH2_SESSION *session, const char *channel_type, unsigned int channel_type_len, unsigned int window_size, unsigned int packet_size, const char *message, unsigned int message_len);
int libssh2_channel_process_startup(LIBSSH2_CHANNEL *channel, const char *request, size_t request_len, const char *message, size_t message_len);
ssize_t libssh2_channel_read_ex(LIBSSH2_CHANNEL *channel, int stream_id, char *buf, size_t buflen);
int libssh2_channel_eof(LIBSSH2_CHANNEL *channel);
int libssh2_channel_send_eof(LIBSSH2_CHANNEL *channel);
int libssh2_channel_close(LIBSSH2_CHANNEL *channel);
int libssh2_channel_wait_closed(LIBSSH2_CHANNEL *channel);
int libssh2_channel_free(LIBSSH2_CHANNEL *channel);
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
