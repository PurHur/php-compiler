<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * RedisCluster VM class — construct + get/set/del over first seed (PECL phpredis; #28094).
 *
 * Full CLUSTER slot routing / MOVED redirects are follow-ups; this surface satisfies
 * feature detection and single-node seed round-trips.
 */
final class VmRedisCluster
{
    public const CLASS_LC = 'rediscluster';

    /** @var array<int, RedisClusterState> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['get'])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('RedisCluster');
        $entry->isInternal = true;

        // Subset of redis_cluster.stub.php failover constants (values match php_redis.h).
        $constants = [
            'OPT_SLAVE_FAILOVER' => 5,
            'FAILOVER_NONE' => 0,
            'FAILOVER_ERROR' => 1,
            'FAILOVER_DISTRIBUTE' => 2,
            'FAILOVER_DISTRIBUTE_SLAVES' => 3,
        ];
        foreach ($constants as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$name] = $const;
            $entry->constNames[$name] = $name;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->constructor = new RedisClusterConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $methods = [
            'get' => new RedisClusterGet(),
            'set' => new RedisClusterSet(),
            'del' => new RedisClusterDel(),
            'close' => new RedisClusterClose(),
        ];
        foreach ($methods as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
            $entry->methodNames[$name] = $name;
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function initObject(ObjectEntry $entry): RedisClusterState
    {
        $state = new RedisClusterState();
        self::$store[$entry->id] = $state;
        $entry->constructed = true;

        return $state;
    }

    public static function requireReceiver(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf('%s must be called on RedisCluster, %s given', $label, VmRedis::typeLabel($var)));
        }
        $object = $var->toObject();
        if (self::CLASS_LC !== \strtolower($object->class->name)) {
            throw new \TypeError(\sprintf('%s must be called on RedisCluster, %s given', $label, $object->class->name));
        }
        if (!$object->constructed) {
            throw new \TypeError(\sprintf('%s must be called on RedisCluster, uninitialized %s given', $label, $object->class->name));
        }

        return $object;
    }

    public static function state(ObjectEntry $entry): RedisClusterState
    {
        $state = self::$store[$entry->id] ?? null;
        if (null === $state) {
            $state = self::initObject($entry);
        }

        return $state;
    }

    /**
     * @return resource
     *
     * @throws \RedisClusterException
     */
    public static function requireSocket(ObjectEntry $entry, string $label)
    {
        $state = self::state($entry);
        if (!$state->connected || null === $state->socket) {
            $message = \sprintf('%s(): Connection lost or not established', $label);
            $state->lastError = $message;
            throw new \RedisClusterException($message);
        }

        return $state->socket;
    }

    /**
     * Parse "host:port" or "host" seed into [host, port].
     *
     * @return array{0: string, 1: int}
     */
    public static function parseSeed(string $seed): array
    {
        $seed = \trim($seed);
        if ('' === $seed) {
            throw new \RedisClusterException('RedisCluster seed must not be empty');
        }
        if (\str_starts_with($seed, '[')) {
            // IPv6 literal [addr]:port
            $close = \strpos($seed, ']');
            if (false === $close) {
                throw new \RedisClusterException('Invalid RedisCluster IPv6 seed: '.$seed);
            }
            $host = \substr($seed, 1, $close - 1);
            $rest = \substr($seed, $close + 1);
            $port = 6379;
            if (\str_starts_with($rest, ':')) {
                $port = (int) \substr($rest, 1);
            }

            return [$host, $port > 0 ? $port : 6379];
        }
        $colon = \strrpos($seed, ':');
        if (false === $colon) {
            return [$seed, 6379];
        }
        $host = \substr($seed, 0, $colon);
        $port = (int) \substr($seed, $colon + 1);

        return [$host, $port > 0 ? $port : 6379];
    }

    /**
     * @return list<string>
     */
    public static function coerceSeedsArg(Variable $var, string $label): array
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return [];
        }
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #2 ($seeds) must be of type ?array, %s given',
                $label,
                VmRedis::typeLabel($var)
            ));
        }
        $out = [];
        foreach ($resolved->toArray()->iterateKeyed(true) as [, $valueVar]) {
            $out[] = VmRedis::coerceValueToString($valueVar, $label, 1, 'seeds');
        }

        return $out;
    }
}

/** RedisCluster::__construct(?string $name, ?array $seeds = null, ...) — #28094. */
final class RedisClusterConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('RedisCluster::__construct() called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('RedisCluster::__construct() must be called on RedisCluster');
        }
        $object = $var->toObject();
        $state = VmRedisCluster::initObject($object);

        $argc = \count($frame->calledArgs) - 1;
        $name = null;
        if ($argc >= 1) {
            $nameVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $nameVar->type) {
                $name = VmRedis::coerceStringArg($frame->calledArgs[1], 'RedisCluster::__construct', 0, 'name');
            }
        }
        $seeds = [];
        if ($argc >= 2) {
            $seeds = VmRedisCluster::coerceSeedsArg($frame->calledArgs[2], 'RedisCluster::__construct');
        }
        $timeout = 0.0;
        if ($argc >= 3) {
            $timeout = VmRedis::coerceFloatArg($frame->calledArgs[3], 'RedisCluster::__construct', 2, 'timeout', 0.0);
        }
        $readTimeout = 0.0;
        if ($argc >= 4) {
            $readTimeout = VmRedis::coerceFloatArg($frame->calledArgs[4], 'RedisCluster::__construct', 3, 'read_timeout', 0.0);
        }
        $persistent = false;
        if ($argc >= 5) {
            $p = $frame->calledArgs[5]->resolveIndirect();
            $persistent = Variable::TYPE_BOOLEAN === $p->type ? $p->toBool() : (bool) $p->toInt();
        }

        $state->name = $name;
        $state->seeds = $seeds;
        $state->timeout = $timeout;
        $state->readTimeout = $readTimeout;
        $state->persistent = $persistent;

        if ([] === $seeds) {
            // Named cluster from php.ini is not wired yet — leave disconnected for feature detection.
            return;
        }

        [$host, $port] = VmRedisCluster::parseSeed($seeds[0]);
        try {
            $socket = $persistent
                ? VmRedisNative::pconnect($host, $port, $timeout)
                : VmRedisNative::connect($host, $port, $timeout);
        } catch (\RedisException $e) {
            $state->lastError = $e->getMessage();
            throw new \RedisClusterException($e->getMessage(), (int) $e->getCode(), $e);
        }
        $state->socket = $socket;
        $state->connected = true;
        $state->host = $host;
        $state->port = $port;
    }
}

final class RedisClusterGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('get');
    }

    public function execute(Frame $frame): void
    {
        $receiver = VmRedisCluster::requireReceiver($frame->calledArgs[0] ?? throw new \LogicException('RedisCluster::get() called without $this'), 'RedisCluster::get()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('RedisCluster::get() expects at least 1 argument, 0 given');
        }
        $key = VmRedis::coerceStringArg($frame->calledArgs[1], 'RedisCluster::get', 0, 'key');
        $socket = VmRedisCluster::requireSocket($receiver, 'RedisCluster::get');
        try {
            $reply = VmRedisNative::get($socket, $key);
        } catch (\RedisException $e) {
            VmRedisCluster::state($receiver)->lastError = $e->getMessage();
            throw new \RedisClusterException($e->getMessage(), (int) $e->getCode(), $e);
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $reply) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string((string) $reply);
    }
}

final class RedisClusterSet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('set');
    }

    public function execute(Frame $frame): void
    {
        $receiver = VmRedisCluster::requireReceiver($frame->calledArgs[0] ?? throw new \LogicException('RedisCluster::set() called without $this'), 'RedisCluster::set()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'RedisCluster::set() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = VmRedis::coerceStringArg($frame->calledArgs[1], 'RedisCluster::set', 0, 'key');
        $value = VmRedis::coerceValueToString($frame->calledArgs[2], 'RedisCluster::set', 1, 'value');
        $socket = VmRedisCluster::requireSocket($receiver, 'RedisCluster::set');
        try {
            VmRedisNative::set($socket, $key, $value);
        } catch (\RedisException $e) {
            VmRedisCluster::state($receiver)->lastError = $e->getMessage();
            throw new \RedisClusterException($e->getMessage(), (int) $e->getCode(), $e);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class RedisClusterDel extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('del');
    }

    public function execute(Frame $frame): void
    {
        $receiver = VmRedisCluster::requireReceiver($frame->calledArgs[0] ?? throw new \LogicException('RedisCluster::del() called without $this'), 'RedisCluster::del()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError('RedisCluster::del() expects at least 1 argument, 0 given');
        }
        $keys = [];
        for ($i = 1; $i < \count($frame->calledArgs); ++$i) {
            $keys[] = VmRedis::coerceStringArg($frame->calledArgs[$i], 'RedisCluster::del', $i - 1, 'key');
        }
        $socket = VmRedisCluster::requireSocket($receiver, 'RedisCluster::del');
        try {
            $reply = VmRedisNative::command($socket, \array_merge(['DEL'], $keys));
        } catch (\RedisException $e) {
            VmRedisCluster::state($receiver)->lastError = $e->getMessage();
            throw new \RedisClusterException($e->getMessage(), (int) $e->getCode(), $e);
        }
        if (!\is_int($reply)) {
            throw new \RedisClusterException('DEL failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($reply);
        }
    }
}

final class RedisClusterClose extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('close');
    }

    public function execute(Frame $frame): void
    {
        $receiver = VmRedisCluster::requireReceiver($frame->calledArgs[0] ?? throw new \LogicException('RedisCluster::close() called without $this'), 'RedisCluster::close()');
        $state = VmRedisCluster::state($receiver);
        if (null !== $state->socket) {
            VmRedisNative::close($state->socket);
            $state->socket = null;
        }
        $state->connected = false;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
