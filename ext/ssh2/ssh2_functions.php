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
        $this->requireSession($frame->calledArgs[0], 'ssh2_fingerprint', 1);
        // No host key without handshake — PECL returns false.
        $frame->returnVar->bool(false);
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
        if (!VmSsh2Session::isAuthed($session)) {
            @\trigger_error('ssh2_exec(): Unable to request a channel from remote host', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ssh2_exec() requires a VM context');
        }
        $wrapped = VmSsh2Stream::wrap($ctx, $session, $command);
        $frame->returnVar->object($wrapped->toObject());
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
