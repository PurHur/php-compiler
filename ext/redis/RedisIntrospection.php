<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Redis connection introspection accessors (pecl-redis redis.c; #28116).
 */
final class RedisGetHost extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('getHost');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::getHost()');
        $state = VmRedis::state($receiver);
        if (null === $frame->returnVar) {
            return;
        }
        if (!$state->connected && '' === $state->host) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($state->host);
    }
}

final class RedisGetPort extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('getPort');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::getPort()');
        $state = VmRedis::state($receiver);
        if (null === $frame->returnVar) {
            return;
        }
        if (!$state->connected && '' === $state->host) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($state->port);
    }
}

final class RedisGetDBNum extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('getDBNum');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::getDBNum()');
        $state = VmRedis::state($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($state->dbNum);
        }
    }
}

final class RedisGetTimeout extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('getTimeout');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::getTimeout()');
        $state = VmRedis::state($receiver);
        if (null === $frame->returnVar) {
            return;
        }
        if (!$state->connected && '' === $state->host) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->float($state->timeout);
    }
}

final class RedisGetReadTimeout extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('getReadTimeout');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::getReadTimeout()');
        $state = VmRedis::state($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->float($state->readTimeout);
        }
    }
}

final class RedisGetPersistentID extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('getPersistentID');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::getPersistentID()');
        $state = VmRedis::state($receiver);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $state->persistentId) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($state->persistentId);
    }
}

final class RedisGetAuth extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('getAuth');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::getAuth()');
        $state = VmRedis::state($receiver);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $state->auth) {
            $frame->returnVar->null();

            return;
        }
        if (\is_string($state->auth)) {
            $frame->returnVar->string($state->auth);

            return;
        }
        // user + password → packed list [user, password] (phpredis ACL form)
        $ht = new HashTable();
        $user = new Variable();
        $user->string($state->auth[0]);
        $ht->append($user);
        $pass = new Variable();
        $pass->string($state->auth[1]);
        $ht->append($pass);
        $frame->returnVar->array($ht);
    }
}

final class RedisGetLastError extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('getLastError');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::getLastError()');
        $state = VmRedis::state($receiver);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $state->lastError) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($state->lastError);
    }
}

final class RedisClearLastError extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('clearLastError');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::clearLastError()');
        $state = VmRedis::state($receiver);
        $state->lastError = null;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class RedisGetMode extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('getMode');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::getMode()');
        $state = VmRedis::state($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($state->mode);
        }
    }
}
