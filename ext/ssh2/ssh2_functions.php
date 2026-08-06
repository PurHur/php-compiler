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
require_once __DIR__.'/VmSsh2Listener.php';
require_once __DIR__.'/VmSsh2Publickey.php';

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
                    Variable::TYPE_INTEGER => 'int',
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
                    Variable::TYPE_INTEGER => 'int',
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

    protected function requireListener(Variable $var, string $fn, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($listener) must be of type SSH2\\Listener, %s given',
                $fn,
                $argNum,
                match ($var->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_INTEGER => 'int',
                    default => 'mixed',
                }
            ));
        }
        $object = $var->toObject();
        if (VmSsh2Listener::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($listener) must be of type SSH2\\Listener, %s given',
                $fn,
                $argNum,
                $object->class->name
            ));
        }

        return VmSsh2Listener::requireLive($object, $fn);
    }

    protected function requirePublickey(Variable $var, string $fn, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($pkey) must be of type SSH2\\Publickey, %s given',
                $fn,
                $argNum,
                match ($var->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_INTEGER => 'int',
                    default => 'mixed',
                }
            ));
        }
        $object = $var->toObject();
        if (VmSsh2Publickey::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($pkey) must be of type SSH2\\Publickey, %s given',
                $fn,
                $argNum,
                $object->class->name
            ));
        }

        return VmSsh2Publickey::requireLive($object, $fn);
    }

    protected function requireChannel(Variable $var, string $fn, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($channel) must be of type SSH2\\Stream, %s given',
                $fn,
                $argNum,
                match ($var->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_INTEGER => 'int',
                    default => 'mixed',
                }
            ));
        }
        $object = $var->toObject();
        if (VmSsh2Stream::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($channel) must be of type SSH2\\Stream, %s given',
                $fn,
                $argNum,
                $object->class->name
            ));
        }

        return VmSsh2Stream::requireLive($object, $fn);
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

/**
 * ssh2_auth_none(resource $session, string $username): array|bool
 *
 * Probe none-auth / list allowed methods (PECL ssh2_auth_none; #26678).
 */
final class ssh2_auth_none extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_auth_none');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_auth_none() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_auth_none', 1);
        $user = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_auth_none', 2, 'username');
        $native = VmSsh2Session::nativeSession($session);
        if (null === $native) {
            $frame->returnVar->bool(false);

            return;
        }
        $result = VmSsh2Native::authNone($native, $user);
        if (\is_bool($result)) {
            if ($result) {
                VmSsh2Session::markAuthed($session);
            }
            $frame->returnVar->bool($result);

            return;
        }
        $ht = new \PHPCompiler\VM\HashTable();
        foreach ($result as $method) {
            $slot = new Variable();
            $slot->string((string) $method);
            $ht->append($slot);
        }
        $frame->returnVar->array($ht);
    }
}

/**
 * ssh2_methods_negotiated(resource $session): array|false
 *
 * Negotiated KEX/crypt/mac/comp map (PECL ssh2_methods_negotiated; #26679).
 */
final class ssh2_methods_negotiated extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_methods_negotiated');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_methods_negotiated() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_methods_negotiated', 1);
        $native = VmSsh2Session::nativeSession($session);
        if (null === $native) {
            $frame->returnVar->bool(false);

            return;
        }
        $raw = VmSsh2Native::sessionMethodsNegotiated($native);
        if (false === $raw) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new \PHPCompiler\VM\HashTable();
        $kex = new Variable();
        $kex->string($raw['kex']);
        $ht->add('kex', $kex);
        $hostkey = new Variable();
        $hostkey->string($raw['hostkey']);
        $ht->add('hostkey', $hostkey);
        foreach (['client_to_server', 'server_to_client'] as $endpointKey) {
            $endpoint = new \PHPCompiler\VM\HashTable();
            foreach (['crypt', 'mac', 'comp', 'lang'] as $field) {
                $slot = new Variable();
                $slot->string($raw[$endpointKey][$field]);
                $endpoint->add($field, $slot);
            }
            $endpointVar = new Variable();
            $endpointVar->array($endpoint);
            $ht->add($endpointKey, $endpointVar);
        }
        $frame->returnVar->array($ht);
    }
}

/**
 * ssh2_auth_hostbased_file(
 *   resource $session,
 *   string $username,
 *   string $hostname,
 *   string $pubkeyfile,
 *   string $privkeyfile,
 *   ?string $passphrase = null,
 *   ?string $local_username = null
 * ): bool
 *
 * Hostbased pubkey auth (PECL ssh2_auth_hostbased_file; #26714).
 */
final class ssh2_auth_hostbased_file extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_auth_hostbased_file');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 5 || $argc > 7) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_auth_hostbased_file() expects between 5 and 7 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_auth_hostbased_file', 1);
        $user = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_auth_hostbased_file', 2, 'username');
        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ssh2_auth_hostbased_file', 3, 'hostname');
        $pubkey = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'ssh2_auth_hostbased_file', 4, 'pubkeyfile');
        $privkey = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'ssh2_auth_hostbased_file', 5, 'privkeyfile');
        $passphrase = null;
        if ($argc >= 6 && Variable::TYPE_NULL !== $frame->calledArgs[5]->resolveIndirect()->type) {
            $passphrase = VmString::coerceStringBuiltinArg($frame->calledArgs[5], 'ssh2_auth_hostbased_file', 6, 'passphrase');
        }
        $localUser = $user;
        if ($argc >= 7 && Variable::TYPE_NULL !== $frame->calledArgs[6]->resolveIndirect()->type) {
            $localUser = VmString::coerceStringBuiltinArg($frame->calledArgs[6], 'ssh2_auth_hostbased_file', 7, 'local_username');
        }
        if ('' === $user) {
            throw new \ValueError('ssh2_auth_hostbased_file(): Argument #2 ($username) must not be empty');
        }
        $native = VmSsh2Session::nativeSession($session);
        if (null === $native) {
            @\trigger_error(
                'ssh2_auth_hostbased_file(): Authentication failed for '.$user.' using hostbased public key',
                \E_USER_WARNING
            );
            $frame->returnVar->bool(false);

            return;
        }
        if (!VmSsh2Native::authHostbasedFromFile(
            $native,
            $user,
            $hostname,
            $pubkey,
            $privkey,
            $passphrase,
            $localUser
        )) {
            @\trigger_error(
                'ssh2_auth_hostbased_file(): Authentication failed for '.$user.' using hostbased public key',
                \E_USER_WARNING
            );
            $frame->returnVar->bool(false);

            return;
        }
        VmSsh2Session::markAuthed($session);
        $frame->returnVar->bool(true);
    }
}

/**
 * ssh2_auth_agent(resource $session, string $username): bool
 *
 * Authenticate via local ssh-agent (PECL ssh2_auth_agent; #26713).
 */
final class ssh2_auth_agent extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_auth_agent');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_auth_agent() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_auth_agent', 1);
        $user = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_auth_agent', 2, 'username');
        if ('' === $user) {
            throw new \ValueError('ssh2_auth_agent(): Argument #2 ($username) must not be empty');
        }
        $native = VmSsh2Session::nativeSession($session);
        if (null === $native) {
            @\trigger_error('ssh2_auth_agent(): Failure initializing ssh-agent support', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $result = VmSsh2Native::authAgent($native, $user);
        if (true === $result) {
            VmSsh2Session::markAuthed($session);
            $frame->returnVar->bool(true);

            return;
        }
        @\trigger_error('ssh2_auth_agent(): '.(string) $result, \E_USER_WARNING);
        $frame->returnVar->bool(false);
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

final class ssh2_auth_pubkey extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_auth_pubkey');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_auth_pubkey() expects between 4 and 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_auth_pubkey', 1);
        $user = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_auth_pubkey', 2, 'username');
        $pubkey = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ssh2_auth_pubkey', 3, 'pubkey');
        $privkey = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'ssh2_auth_pubkey', 4, 'privkey');
        $passphrase = null;
        if ($argc >= 5) {
            $passphrase = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'ssh2_auth_pubkey', 5, 'passphrase');
        }
        if ('' === $user) {
            throw new \ValueError('ssh2_auth_pubkey(): Argument #2 ($username) must not be empty');
        }
        $native = VmSsh2Session::nativeSession($session);
        if (null === $native) {
            @\trigger_error('ssh2_auth_pubkey(): Authentication failed for '.$user.' using public key', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        if (!VmSsh2Native::authPubkeyFromMemory($native, $user, $pubkey, $privkey, $passphrase)) {
            @\trigger_error('ssh2_auth_pubkey(): Authentication failed for '.$user.' using public key', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        VmSsh2Session::markAuthed($session);
        $frame->returnVar->bool(true);
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
 * ssh2_send_eof(resource $channel): bool
 *
 * Send EOF on a channel stream (PECL ssh2_send_eof; #26736).
 */
final class ssh2_send_eof extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_send_eof');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_send_eof() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $channel = $this->requireChannel($frame->calledArgs[0], 'ssh2_send_eof', 1);
        $native = VmSsh2Stream::nativeChannel($channel);
        if (null === $native) {
            @\trigger_error('ssh2_send_eof(): Couldn\'t send EOF to channel (Return code -1)', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $ok = VmSsh2Native::channelSendEof($native);
        if (!$ok) {
            @\trigger_error('ssh2_send_eof(): Couldn\'t send EOF to channel (Return code -1)', \E_USER_WARNING);
        }
        $frame->returnVar->bool($ok);
    }
}

/**
 * ssh2_send_signal(resource $channel, string $signal): bool
 *
 * Send a signal to a remote process on a channel (PECL ssh2_send_signal; #26736).
 * Signal name without SIG prefix (e.g. "TERM", "HUP").
 */
final class ssh2_send_signal extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_send_signal');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_send_signal() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $channel = $this->requireChannel($frame->calledArgs[0], 'ssh2_send_signal', 1);
        $signal = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_send_signal', 2, 'signal');
        $native = VmSsh2Stream::nativeChannel($channel);
        if (null === $native) {
            @\trigger_error(\sprintf(
                'ssh2_send_signal(): Couldn\'t send signal %s to channel (Return code -1)',
                $signal
            ), \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $ok = VmSsh2Native::channelSendSignal($native, $signal);
        if (!$ok) {
            @\trigger_error(\sprintf(
                'ssh2_send_signal(): Couldn\'t send signal %s to channel (Return code -1)',
                $signal
            ), \E_USER_WARNING);
        }
        $frame->returnVar->bool($ok);
    }
}

/**
 * ssh2_shell(resource $session, string $term_type = "vanilla", ?array $env = null, int $width = 80, int $height = 25, int $width_height_type = SSH2_TERM_UNIT_CHARS): resource|false
 */
final class ssh2_shell extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_shell');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 6) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_shell() expects between 1 and 6 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_shell', 1);
        $term = 'vanilla';
        if ($argc >= 2) {
            $term = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_shell', 2, 'term_type');
        }
        // env ($argc >= 3) accepted for arity parity; ignored in v1 (PECL setenv path).
        $width = 80;
        $height = 25;
        $unit = Ssh2Constants::TERM_UNIT_CHARS;
        if ($argc >= 4) {
            $width = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'ssh2_shell', 4, 'width');
        }
        if ($argc >= 5) {
            $height = VmMath::parseIntBuiltinArgForFrame($frame, 4, 'ssh2_shell', 5, 'height');
        }
        if ($argc >= 6) {
            $unit = VmMath::parseIntBuiltinArgForFrame($frame, 5, 'ssh2_shell', 6, 'width_height_type');
        }
        if (!VmSsh2Session::isAuthed($session)) {
            @\trigger_error('ssh2_shell(): Unable to request a channel from remote host', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $native = VmSsh2Session::nativeSession($session);
        if (null === $native) {
            @\trigger_error('ssh2_shell(): Unable to request a channel from remote host', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ssh2_shell() requires a VM context');
        }
        $channel = VmSsh2Native::channelShellOpen(
            $native,
            $term,
            $width,
            $height,
            Ssh2Constants::TERM_UNIT_CHARS === $unit ? 1 : 0
        );
        if (null === $channel) {
            @\trigger_error('ssh2_shell(): Unable to request shell from remote host', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $wrapped = VmSsh2Stream::wrap($ctx, $session, 'shell:'.$term, $channel);
        $frame->returnVar->object($wrapped->toObject());
    }
}

/**
 * ssh2_tunnel(resource $session, string $host, int $port): resource|false
 *
 * Direct-tcpip channel stream (PECL ssh2_tunnel; #26677).
 */
final class ssh2_tunnel extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_tunnel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_tunnel() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_tunnel', 1);
        $host = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_tunnel', 2, 'host');
        $port = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'ssh2_tunnel', 3, 'port');
        if (!VmSsh2Session::isAuthed($session)) {
            @\trigger_error('ssh2_tunnel(): Unable to request a channel from remote host', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $native = VmSsh2Session::nativeSession($session);
        if (null === $native) {
            @\trigger_error('ssh2_tunnel(): Unable to request a channel from remote host', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ssh2_tunnel() requires a VM context');
        }
        $channel = VmSsh2Native::channelDirectTcpip($native, $host, $port);
        if (null === $channel) {
            @\trigger_error('ssh2_tunnel(): Unable to request a channel from remote host', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $wrapped = VmSsh2Stream::wrap($ctx, $session, 'tunnel:'.$host.':'.$port, $channel);
        $frame->returnVar->object($wrapped->toObject());
    }
}

/**
 * ssh2_forward_listen(resource $session, int $port[, string $host[, int $max_connections = 16]]): resource|false
 *
 * Remote port forward listener (PECL ssh2_forward_listen; #26715).
 */
final class ssh2_forward_listen extends Ssh2Function
{
    private const DEFAULT_MAX_QUEUED = 16;

    public function __construct()
    {
        parent::__construct('ssh2_forward_listen');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_forward_listen() expects between 2 and 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_forward_listen', 1);
        $port = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'ssh2_forward_listen', 2, 'port');
        $host = null;
        if ($argc >= 3 && Variable::TYPE_NULL !== $frame->calledArgs[2]->resolveIndirect()->type) {
            $host = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ssh2_forward_listen', 3, 'host');
        }
        $maxConnections = self::DEFAULT_MAX_QUEUED;
        if ($argc >= 4) {
            $maxConnections = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'ssh2_forward_listen', 4, 'max_connections');
        }
        if (!VmSsh2Session::isAuthed($session)) {
            @\trigger_error('ssh2_forward_listen(): Failure listening on remote port', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $native = VmSsh2Session::nativeSession($session);
        if (null === $native) {
            @\trigger_error('ssh2_forward_listen(): Failure listening on remote port', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ssh2_forward_listen() requires a VM context');
        }
        $listener = VmSsh2Native::channelForwardListen($native, $port, $host, $maxConnections);
        if (null === $listener) {
            @\trigger_error('ssh2_forward_listen(): Failure listening on remote port', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $wrapped = VmSsh2Listener::wrap($ctx, $session, $port, $host, $listener);
        $frame->returnVar->object($wrapped->toObject());
    }
}

/**
 * ssh2_forward_accept(resource $listener): resource|false
 *
 * Accept a channel on a remote forward listener (PECL ssh2_forward_accept; #26715).
 */
final class ssh2_forward_accept extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_forward_accept');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_forward_accept() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $listener = $this->requireListener($frame->calledArgs[0], 'ssh2_forward_accept', 1);
        $native = VmSsh2Listener::nativeListener($listener);
        if (null === $native) {
            $frame->returnVar->bool(false);

            return;
        }
        $channel = VmSsh2Native::channelForwardAccept($native);
        if (null === $channel) {
            $frame->returnVar->bool(false);

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ssh2_forward_accept() requires a VM context');
        }
        $session = VmSsh2Listener::session($listener);
        $wrapped = VmSsh2Stream::wrap($ctx, $session, 'forward-accept', $channel);
        $frame->returnVar->object($wrapped->toObject());
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
 * ssh2_sftp_statvfs(resource $sftp, string $path): array|false
 *
 * Remote filesystem statistics (libssh2_sftp_statvfs; #26740).
 */
final class ssh2_sftp_statvfs extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_sftp_statvfs');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_sftp_statvfs() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $sftpObj = $this->requireSftp($frame->calledArgs[0], 'ssh2_sftp_statvfs', 1);
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_sftp_statvfs', 2, 'path');
        $native = VmSsh2Sftp::nativeSftp($sftpObj);
        if (null === $native) {
            @\trigger_error('ssh2_sftp_statvfs(): Failed to statvfs remote filesystem', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $raw = VmSsh2Native::sftpStatvfs($native, $path);
        if (false === $raw) {
            @\trigger_error('ssh2_sftp_statvfs(): Failed to statvfs remote filesystem', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new \PHPCompiler\VM\HashTable();
        foreach ($raw as $key => $value) {
            $slot = new Variable();
            $slot->int((int) $value);
            $ht->add((string) $key, $slot);
        }
        $frame->returnVar->array($ht);
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

/**
 * ssh2_publickey_init(resource $session): resource|false
 *
 * Publickey subsystem init (PECL ssh2_publickey_init; #26717).
 */
final class ssh2_publickey_init extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_publickey_init');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_publickey_init() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $session = $this->requireSession($frame->calledArgs[0], 'ssh2_publickey_init', 1);
        if (!VmSsh2Session::isAuthed($session)) {
            @\trigger_error('ssh2_publickey_init(): Connection not authenticated', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $native = VmSsh2Session::nativeSession($session);
        if (null === $native) {
            @\trigger_error('ssh2_publickey_init(): Unable to initialize publickey subsystem', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $pkey = VmSsh2Native::publickeyInit($native);
        if (null === $pkey) {
            @\trigger_error('ssh2_publickey_init(): Unable to initialize publickey subsystem', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ssh2_publickey_init() requires a VM context');
        }
        $wrapped = VmSsh2Publickey::wrap($ctx, $session, $pkey);
        $frame->returnVar->object($wrapped->toObject());
    }
}

/**
 * ssh2_publickey_add(resource $pkey, string $algoname, string $blob[, bool $overwrite = false [, array $attributes = null]]): bool
 */
final class ssh2_publickey_add extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_publickey_add');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_publickey_add() expects between 3 and 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pkeyObj = $this->requirePublickey($frame->calledArgs[0], 'ssh2_publickey_add', 1);
        $algo = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_publickey_add', 2, 'algoname');
        $blob = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ssh2_publickey_add', 3, 'blob');
        $overwrite = false;
        if ($argc >= 4) {
            $overwrite = (bool) $frame->calledArgs[3]->resolveIndirect()->toBool();
        }
        if ($argc >= 5) {
            $attrsVar = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_NULL !== $attrsVar->type && Variable::TYPE_ARRAY !== $attrsVar->type) {
                throw new \TypeError(\sprintf(
                    'ssh2_publickey_add(): Argument #5 ($attributes) must be of type ?array, %s given',
                    match ($attrsVar->type) {
                        Variable::TYPE_STRING => 'string',
                        Variable::TYPE_INTEGER => 'int',
                        Variable::TYPE_BOOLEAN => 'bool',
                        default => 'mixed',
                    }
                ));
            }
            // Attribute map is accepted for PECL arity parity; libssh2 add uses empty attrs (#26717).
        }
        $native = VmSsh2Publickey::nativePublickey($pkeyObj);
        if (null === $native) {
            @\trigger_error('ssh2_publickey_add(): Unable to add '.$algo.' key', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        if (!VmSsh2Native::publickeyAdd($native, $algo, $blob, $overwrite)) {
            @\trigger_error('ssh2_publickey_add(): Unable to add '.$algo.' key', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(true);
    }
}

/**
 * ssh2_publickey_remove(resource $pkey, string $algoname, string $blob): bool
 */
final class ssh2_publickey_remove extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_publickey_remove');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_publickey_remove() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pkeyObj = $this->requirePublickey($frame->calledArgs[0], 'ssh2_publickey_remove', 1);
        $algo = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ssh2_publickey_remove', 2, 'algoname');
        $blob = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ssh2_publickey_remove', 3, 'blob');
        $native = VmSsh2Publickey::nativePublickey($pkeyObj);
        if (null === $native) {
            @\trigger_error('ssh2_publickey_remove(): Unable to remove '.$algo.' key', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        if (!VmSsh2Native::publickeyRemove($native, $algo, $blob)) {
            @\trigger_error('ssh2_publickey_remove(): Unable to remove '.$algo.' key', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(true);
    }
}

/**
 * ssh2_publickey_list(resource $pkey): array|false
 */
final class ssh2_publickey_list extends Ssh2Function
{
    public function __construct()
    {
        parent::__construct('ssh2_publickey_list');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ssh2_publickey_list() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pkeyObj = $this->requirePublickey($frame->calledArgs[0], 'ssh2_publickey_list', 1);
        $native = VmSsh2Publickey::nativePublickey($pkeyObj);
        if (null === $native) {
            @\trigger_error('ssh2_publickey_list(): Unable to list keys on remote server', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $keys = VmSsh2Native::publickeyList($native);
        if (false === $keys) {
            @\trigger_error('ssh2_publickey_list(): Unable to list keys on remote server', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new \PHPCompiler\VM\HashTable();
        $idx = 0;
        foreach ($keys as $key) {
            $row = new \PHPCompiler\VM\HashTable();
            $nameVar = new Variable();
            $nameVar->string($key['name']);
            $row->add('name', $nameVar);
            $blobVar = new Variable();
            $blobVar->string($key['blob']);
            $row->add('blob', $blobVar);
            $attrsHt = new \PHPCompiler\VM\HashTable();
            foreach ($key['attrs'] as $attrName => $attrVal) {
                $attrVar = new Variable();
                $attrVar->string($attrVal);
                $attrsHt->add((string) $attrName, $attrVar);
            }
            $attrsVar = new Variable();
            $attrsVar->array($attrsHt);
            $row->add('attrs', $attrsVar);
            $rowVar = new Variable();
            $rowVar->array($row);
            $ht->addIndex($idx++, $rowVar);
        }
        $frame->returnVar->array($ht);
    }
}
