<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ssh2;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

require_once __DIR__.'/Ssh2Constants.php';
require_once __DIR__.'/VmSsh2Native.php';
require_once __DIR__.'/VmSsh2Session.php';
require_once __DIR__.'/VmSsh2Stream.php';
require_once __DIR__.'/VmSsh2Sftp.php';

/** Shared JIT stub for ssh2_* v1 (#6385). */
abstract class Ssh2Function extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException($this->getName().'() is not implemented for JIT in this compiler build (issue #6385)');
    }

    protected function requireSession(Variable $var, string $fn, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($session) must be of type SSH2\\Session, %s given',
                $fn,
                $argNum,
                match ($var->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_INT => 'int',
                    default => 'mixed',
                }
            ));
        }
        $object = $var->toObject();
        if (VmSsh2Session::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($session) must be of type SSH2\\Session, %s given',
                $fn,
                $argNum,
                $object->class->name
            ));
        }

        return VmSsh2Session::requireLive($object, $fn);
    }

    protected function requireSftp(Variable $var, string $fn, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($sftp) must be of type SSH2\\Sftp, %s given',
                $fn,
                $argNum,
                match ($var->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_INT => 'int',
                    default => 'mixed',
                }
            ));
        }
        $object = $var->toObject();
        if (VmSsh2Sftp::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($sftp) must be of type SSH2\\Sftp, %s given',
                $fn,
                $argNum,
                $object->class->name
            ));
        }

        return VmSsh2Sftp::requireLive($object, $fn);
    }
}

/**
 * ssh2_connect(string $host, int $port = 22, ?array $methods = null, ?array $callbacks = null): resource|false
 */
final class ssh2_connect extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_connect');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_connect() expects between 1 and 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $host = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'ssh2_connect', 1, 'host');
        $port = 22;
        if ($argc >= 2) {
            $port = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'ssh2_connect', 2, 'port');
        }
        if ($port < 1 || $port > 65535) {
            throw new \ValueError('ssh2_connect(): Argument #2 ($port) must be between 1 and 65535');
        }
        // methods / callbacks accepted for arity parity; ignored in v1 without libssh2 handshake.
        if (!VmSsh2Native::tcpProbe($host, $port)) {
            @\trigger_error(
                \sprintf('ssh2_connect(): Unable to connect to %s on port %d', $host, $port),
                \E_USER_WARNING
            );
            $frame->returnVar->bool(false);

            return;
        }
        // TCP open but no libssh2 — PECL would negotiate; we cannot.
        if (!VmSsh2Native::hasLibssh2()) {
            @\trigger_error(
                \sprintf('ssh2_connect(): Error starting up SSH connection to %s:%d', $host, $port),
                \E_USER_WARNING
            );
            $frame->returnVar->bool(false);

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ssh2_connect() requires a VM context');
        }
        $native = VmSsh2Native::handshake($host, $port);
        if (null === $native) {
            @\trigger_error(
                \sprintf('ssh2_connect(): Error starting up SSH connection to %s:%d', $host, $port),
                \E_USER_WARNING
            );
            $frame->returnVar->bool(false);

            return;
        }
        $wrapped = VmSsh2Session::wrap($ctx, $host, $port, $native['sock'], $native['session']);
        $frame->returnVar->object($wrapped->toObject());
    }
}

final class ssh2_disconnect extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_disconnect');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_disconnect() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_disconnect', 1);
        $frame->returnVar->bool(VmSsh2Session::close($session));
    }
}

final class ssh2_auth_password extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_auth_password');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_auth_password() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_auth_password', 1);
        $user = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_auth_password', 2, 'username');
        $password = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ssh2_auth_password', 3, 'password');
        if ('' === $user) {
            throw new \ValueError('ssh2_auth_password(): Argument #2 ($username) must not be empty');
        }
        $native = VmSsh2Session::nativeSession($session);
        if (null === $native) {
            @\trigger_error('ssh2_auth_password(): Authentication failed for '.$user.' using password', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        if (!VmSsh2Native::authPassword($native, $user, $password)) {
            @\trigger_error('ssh2_auth_password(): Authentication failed for '.$user.' using password', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        VmSsh2Session::markAuthed($session);
        $frame->returnVar->bool(true);
    }
}

final class ssh2_auth_pubkey_file extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_auth_pubkey_file');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_auth_pubkey_file() expects between 4 and 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_auth_pubkey_file', 1);
        $user = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_auth_pubkey_file', 2, 'username');
        $pubkey = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ssh2_auth_pubkey_file', 3, 'pubkeyfile');
        $privkey = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'ssh2_auth_pubkey_file', 4, 'privkeyfile');
        $passphrase = null;
        if ($argc >= 5) {
            $passphrase = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'ssh2_auth_pubkey_file', 5, 'passphrase');
        }
        if ('' === $user) {
            throw new \ValueError('ssh2_auth_pubkey_file(): Argument #2 ($username) must not be empty');
        }
        $pubkey = self::expandHomePath($pubkey);
        $privkey = self::expandHomePath($privkey);
        $native = VmSsh2Session::nativeSession($session);
        if (null === $native) {
            @\trigger_error('ssh2_auth_pubkey_file(): Authentication failed for '.$user.' using public key', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        if (!VmSsh2Native::authPubkeyFromFile($native, $user, $pubkey, $privkey, $passphrase)) {
            @\trigger_error('ssh2_auth_pubkey_file(): Authentication failed for '.$user.' using public key', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        VmSsh2Session::markAuthed($session);
        $frame->returnVar->bool(true);
    }

    private static function expandHomePath(string $path): string
    {
        if (\strlen($path) >= 2 && '~' === $path[0] && '/' === $path[1]) {
            $home = getenv('HOME');
            if (\is_string($home) && '' !== $home) {
                return $home.\substr($path, 1);
            }
        }

        return $path;
    }
}

final class ssh2_fingerprint extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_fingerprint');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_fingerprint() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_fingerprint', 1);
        $flags = Ssh2Constants::FINGERPRINT_MD5 | Ssh2Constants::FINGERPRINT_HEX;
        if ($argc >= 2) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'ssh2_fingerprint', 2, 'flags');
        }
        $native = VmSsh2Session::nativeSession($session);
        if (null === $native) {
            @\trigger_error('ssh2_fingerprint(): Unable to retrieve fingerprint from specified session', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $fp = VmSsh2Native::hostkeyFingerprint($native, $flags);
        if (false === $fp) {
            @\trigger_error('ssh2_fingerprint(): No fingerprint available using specified hash', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($fp);
    }
}

final class ssh2_exec extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_exec');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_exec() expects between 2 and 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_exec', 1);
        $command = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_exec', 2, 'command');
        // pty / env / width / height accepted for arity parity; ignored in v1.
        if (!VmSsh2Session::isAuthed($session)) {
            @\trigger_error('ssh2_exec(): Unable to request a channel from remote host', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $native = VmSsh2Session::nativeSession($session);
        if (null === $native) {
            @\trigger_error('ssh2_exec(): Unable to request a channel from remote host', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ssh2_exec() requires a VM context');
        }
        $out = VmSsh2Native::channelExecDrain($native, $command);
        if (false === $out) {
            @\trigger_error('ssh2_exec(): Unable to request a channel from remote host', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        // PECL returns a readable stream resource; drain into php://memory (#26576).
        require_once \dirname(__DIR__).'/standard/VmPhpMemoryStream.php';
        $handle = \PHPCompiler\ext\standard\VmPhpMemoryStream::openWithBuffer('php://memory', $out, 'rb');
        if (false === $handle) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->streamHandle($handle, $ctx);
    }
}

final class ssh2_fetch_stream extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_fetch_stream');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_fetch_stream() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('ssh2_fetch_stream(): Argument #1 ($channel) must be of type SSH2\\Stream, mixed given');
        }
        $object = $var->toObject();
        if (VmSsh2Stream::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                'ssh2_fetch_stream(): Argument #1 ($channel) must be of type SSH2\\Stream, %s given',
                $object->class->name
            ));
        }
        VmSsh2Stream::requireLive($object, 'ssh2_fetch_stream');
        VmMath::parseIntBuiltinArgForFrame($frame, 1, 'ssh2_fetch_stream', 2, 'streamid');
        // v1: return the same channel object (PECL returns stderr/stdio sibling stream).
        $frame->returnVar->object($object);
    }
}

/**
 * ssh2_sftp(resource $session): resource|false
 */
final class ssh2_sftp extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_sftp');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_sftp() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_sftp', 1);
        if (!VmSsh2Session::isAuthed($session)) {
            @\trigger_error('ssh2_sftp(): Unable to startup SFTP channel', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $native = VmSsh2Session::nativeSession($session);
        if (null === $native) {
            @\trigger_error('ssh2_sftp(): Unable to startup SFTP channel', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $sftp = VmSsh2Native::sftpInit($native);
        if (null === $sftp) {
            @\trigger_error('ssh2_sftp(): Unable to startup SFTP channel', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ssh2_sftp() requires a VM context');
        }
        $wrapped = VmSsh2Sftp::wrap($ctx, $session, $sftp);
        $frame->returnVar->object($wrapped->toObject());
    }
}

/**
 * ssh2_scp_recv(resource $session, string $remote_file, string $local_file): bool
 */
final class ssh2_scp_recv extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_scp_recv');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_scp_recv() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_scp_recv', 1);
        $remote = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_scp_recv', 2, 'remote_file');
        $local = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ssh2_scp_recv', 3, 'local_file');
        $native = VmSsh2Session::nativeSession($session);
        if (null === $native || !VmSsh2Session::isAuthed($session)) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(VmSsh2Native::scpRecv($native, $remote, $local));
    }
}

/**
 * ssh2_scp_send(resource $session, string $local_file, string $remote_file, int $create_mode = 0644): bool
 */
final class ssh2_scp_send extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_scp_send');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_scp_send() expects between 3 and 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_scp_send', 1);
        $local = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_scp_send', 2, 'local_file');
        $remote = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ssh2_scp_send', 3, 'remote_file');
        $mode = 0644;
        if ($argc >= 4) {
            $mode = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'ssh2_scp_send', 4, 'create_mode');
        }
        $native = VmSsh2Session::nativeSession($session);
        if (null === $native || !VmSsh2Session::isAuthed($session)) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(VmSsh2Native::scpSend($native, $local, $remote, $mode));
    }
}

/**
 * ssh2_sftp_stat(resource $sftp, string $path): array|false
 */
final class ssh2_sftp_stat extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_sftp_stat');
    }

    public function execute(Frame $frame): void
    {
        self::runStat($this, $frame, 'ssh2_sftp_stat', VmSsh2Native::SFTP_STAT);
    }

    /**
     * Shared body for ssh2_sftp_stat / ssh2_sftp_lstat (#26609).
     */
    public static function runStat(Ssh2Function $self, Frame $frame, string $fn, int $statType): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 2 arguments, %d given',
                $fn,
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $sftpObj = $self->requireSftp($frame->calledArgs[0], $fn, 1);
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $fn, 2, 'path');
        $native = VmSsh2Sftp::nativeSftp($sftpObj);
        if (null === $native) {
            @\trigger_error($fn.'(): Failed to stat remote file', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $raw = VmSsh2Native::sftpStat($native, $path, $statType);
        if (false === $raw) {
            @\trigger_error($fn.'(): Failed to stat remote file', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new \PHPCompiler\VM\HashTable();
        foreach ($raw as $key => $value) {
            $slot = new Variable();
            $slot->int((int) $value);
            if (\is_int($key)) {
                $ht->addIndex($key, $slot);
            } else {
                $ht->add((string) $key, $slot);
            }
        }
        $frame->returnVar->array($ht);
    }
}

/**
 * ssh2_sftp_lstat(resource $sftp, string $path): array|false
 */
final class ssh2_sftp_lstat extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_sftp_lstat');
    }

    public function execute(Frame $frame): void
    {
        ssh2_sftp_stat::runStat($this, $frame, 'ssh2_sftp_lstat', VmSsh2Native::SFTP_LSTAT);
    }
}

/**
 * ssh2_sftp_mkdir(resource $sftp, string $dirname, int $mode = 0777, bool $recursive = false): bool
 */
final class ssh2_sftp_mkdir extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_sftp_mkdir');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_sftp_mkdir() expects between 2 and 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $sftpObj = $this->requireSftp($frame->calledArgs[0], 'ssh2_sftp_mkdir', 1);
        $dirname = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_sftp_mkdir', 2, 'dirname');
        $mode = 0777;
        if ($argc >= 3) {
            $mode = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'ssh2_sftp_mkdir', 3, 'mode');
        }
        $recursive = false;
        if ($argc >= 4) {
            $recursive = (bool) $frame->calledArgs[3]->resolveIndirect()->toBool();
        }
        $native = VmSsh2Sftp::nativeSftp($sftpObj);
        if (null === $native || '' === $dirname) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(VmSsh2Native::sftpMkdir($native, $dirname, $mode, $recursive));
    }
}

/**
 * ssh2_sftp_rmdir(resource $sftp, string $dirname): bool
 */
final class ssh2_sftp_rmdir extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_sftp_rmdir');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_sftp_rmdir() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $sftpObj = $this->requireSftp($frame->calledArgs[0], 'ssh2_sftp_rmdir', 1);
        $dirname = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_sftp_rmdir', 2, 'dirname');
        $native = VmSsh2Sftp::nativeSftp($sftpObj);
        if (null === $native) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(VmSsh2Native::sftpRmdir($native, $dirname));
    }
}

/**
 * ssh2_sftp_unlink(resource $sftp, string $filename): bool
 */
final class ssh2_sftp_unlink extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_sftp_unlink');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_sftp_unlink() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $sftpObj = $this->requireSftp($frame->calledArgs[0], 'ssh2_sftp_unlink', 1);
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_sftp_unlink', 2, 'filename');
        $native = VmSsh2Sftp::nativeSftp($sftpObj);
        if (null === $native) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(VmSsh2Native::sftpUnlink($native, $filename));
    }
}

/**
 * ssh2_sftp_rename(resource $sftp, string $from, string $to): bool
 */
final class ssh2_sftp_rename extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_sftp_rename');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_sftp_rename() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $sftpObj = $this->requireSftp($frame->calledArgs[0], 'ssh2_sftp_rename', 1);
        $from = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_sftp_rename', 2, 'from');
        $to = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ssh2_sftp_rename', 3, 'to');
        $native = VmSsh2Sftp::nativeSftp($sftpObj);
        if (null === $native) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(VmSsh2Native::sftpRename($native, $from, $to));
    }
}

/**
 * ssh2_sftp_chmod(resource $sftp, string $filename, int $mode): bool
 */
final class ssh2_sftp_chmod extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_sftp_chmod');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_sftp_chmod() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $sftpObj = $this->requireSftp($frame->calledArgs[0], 'ssh2_sftp_chmod', 1);
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_sftp_chmod', 2, 'filename');
        $mode = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'ssh2_sftp_chmod', 3, 'mode');
        $native = VmSsh2Sftp::nativeSftp($sftpObj);
        if (null === $native || '' === $filename) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(VmSsh2Native::sftpChmod($native, $filename, $mode));
    }
}

/**
 * ssh2_sftp_realpath(resource $sftp, string $filename): string|false
 */
final class ssh2_sftp_realpath extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_sftp_realpath');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_sftp_realpath() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $sftpObj = $this->requireSftp($frame->calledArgs[0], 'ssh2_sftp_realpath', 1);
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_sftp_realpath', 2, 'filename');
        $native = VmSsh2Sftp::nativeSftp($sftpObj);
        if (null === $native) {
            @\trigger_error(
                \sprintf("ssh2_sftp_realpath(): Unable to resolve realpath for '%s'", $filename),
                \E_USER_WARNING
            );
            $frame->returnVar->bool(false);

            return;
        }
        $resolved = VmSsh2Native::sftpRealpath($native, $filename);
        if (false === $resolved) {
            @\trigger_error(
                \sprintf("ssh2_sftp_realpath(): Unable to resolve realpath for '%s'", $filename),
                \E_USER_WARNING
            );
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($resolved);
    }
}

/**
 * ssh2_sftp_symlink(resource $sftp, string $target, string $link): bool
 */
final class ssh2_sftp_symlink extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_sftp_symlink');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_sftp_symlink() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $sftpObj = $this->requireSftp($frame->calledArgs[0], 'ssh2_sftp_symlink', 1);
        $target = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_sftp_symlink', 2, 'target');
        $link = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ssh2_sftp_symlink', 3, 'link');
        $native = VmSsh2Sftp::nativeSftp($sftpObj);
        if (null === $native || '' === $target || '' === $link) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(VmSsh2Native::sftpSymlink($native, $target, $link));
    }
}

/**
 * ssh2_sftp_readlink(resource $sftp, string $link): string|false
 */
final class ssh2_sftp_readlink extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_sftp_readlink');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_sftp_readlink() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $sftpObj = $this->requireSftp($frame->calledArgs[0], 'ssh2_sftp_readlink', 1);
        $link = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_sftp_readlink', 2, 'link');
        $native = VmSsh2Sftp::nativeSftp($sftpObj);
        if (null === $native) {
            @\trigger_error(
                \sprintf("ssh2_sftp_readlink(): Unable to read link '%s'", $link),
                \E_USER_WARNING
            );
            $frame->returnVar->bool(false);

            return;
        }
        $resolved = VmSsh2Native::sftpReadlink($native, $link);
        if (false === $resolved) {
            @\trigger_error(
                \sprintf("ssh2_sftp_readlink(): Unable to read link '%s'", $link),
                \E_USER_WARNING
            );
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($resolved);
    }
}
