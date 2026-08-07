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
 * RedisArray VM class — construct + get/set/del with host hashing (PECL phpredis; #28094).
 */
final class VmRedisArray
{
    public const CLASS_LC = 'redisarray';

    /** @var array<int, RedisArrayState> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['get'])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('RedisArray');
        $entry->isInternal = true;

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->constructor = new RedisArrayConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $methods = [
            'get' => new RedisArrayGet(),
            'set' => new RedisArraySet(),
            'del' => new RedisArrayDel(),
            '_hosts' => new RedisArrayHosts(),
            '_target' => new RedisArrayTarget(),
        ];
        foreach ($methods as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
            $entry->methodNames[$name] = $name;
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function initObject(ObjectEntry $entry): RedisArrayState
    {
        $state = new RedisArrayState();
        self::$store[$entry->id] = $state;
        $entry->constructed = true;

        return $state;
    }

    public static function requireReceiver(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf('%s must be called on RedisArray, %s given', $label, VmRedis::typeLabel($var)));
        }
        $object = $var->toObject();
        if (self::CLASS_LC !== \strtolower($object->class->name)) {
            throw new \TypeError(\sprintf('%s must be called on RedisArray, %s given', $label, $object->class->name));
        }
        if (!$object->constructed) {
            throw new \TypeError(\sprintf('%s must be called on RedisArray, uninitialized %s given', $label, $object->class->name));
        }

        return $object;
    }

    public static function state(ObjectEntry $entry): RedisArrayState
    {
        $state = self::$store[$entry->id] ?? null;
        if (null === $state) {
            $state = self::initObject($entry);
        }

        return $state;
    }

    /**
     * @return list<array{host: string, port: int}>
     */
    public static function coerceHostsArg(Variable $var, string $label): array
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_STRING === $resolved->type) {
            // Named array from ini — not wired; empty host list until seeds supplied.
            return [];
        }
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($name_or_hosts) must be of type string|array, %s given',
                $label,
                VmRedis::typeLabel($var)
            ));
        }
        $out = [];
        foreach ($resolved->toArray()->iterateKeyed(true) as [, $valueVar]) {
            $seed = VmRedis::coerceValueToString($valueVar, $label, 0, 'name_or_hosts');
            [$host, $port] = VmRedisCluster::parseSeed($seed);
            $out[] = ['host' => $host, 'port' => $port];
        }

        return $out;
    }

    public static function hostIndex(RedisArrayState $state, string $key): int
    {
        $n = \count($state->hosts);
        if ($n < 1) {
            throw new \RedisException('RedisArray has no hosts configured');
        }

        return (int) (\sprintf('%u', \crc32($key)) % $n);
    }

    /**
     * @return resource
     *
     * @throws \RedisException
     */
    public static function socketForKey(ObjectEntry $entry, string $key, string $label)
    {
        $state = self::state($entry);
        $idx = self::hostIndex($state, $key);
        if (!isset($state->sockets[$idx]) || null === $state->sockets[$idx]) {
            $host = $state->hosts[$idx];
            try {
                $state->sockets[$idx] = VmRedisNative::connect($host['host'], $host['port'], 0.0);
            } catch (\RedisException $e) {
                $state->lastError = $e->getMessage();
                throw $e;
            }
        }

        return $state->sockets[$idx];
    }
}

/** RedisArray::__construct(string|array $name_or_hosts, ?array $options = null) — #28094. */
final class RedisArrayConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('RedisArray::__construct() called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('RedisArray::__construct() must be called on RedisArray');
        }
        $object = $var->toObject();
        $state = VmRedisArray::initObject($object);

        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('RedisArray::__construct() expects at least 1 argument, 0 given');
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING === $arg->type) {
            $state->name = $arg->toString();
            $state->hosts = [];

            return;
        }
        $state->hosts = VmRedisArray::coerceHostsArg($frame->calledArgs[1], 'RedisArray::__construct');
        $state->sockets = \array_fill(0, \count($state->hosts), null);
    }
}

final class RedisArrayGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('get');
    }

    public function execute(Frame $frame): void
    {
        $receiver = VmRedisArray::requireReceiver($frame->calledArgs[0] ?? throw new \LogicException('RedisArray::get() called without $this'), 'RedisArray::get()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('RedisArray::get() expects at least 1 argument, 0 given');
        }
        $key = VmRedis::coerceStringArg($frame->calledArgs[1], 'RedisArray::get', 0, 'key');
        $socket = VmRedisArray::socketForKey($receiver, $key, 'RedisArray::get');
        $reply = VmRedisNative::get($socket, $key);
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

final class RedisArraySet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('set');
    }

    public function execute(Frame $frame): void
    {
        $receiver = VmRedisArray::requireReceiver($frame->calledArgs[0] ?? throw new \LogicException('RedisArray::set() called without $this'), 'RedisArray::set()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'RedisArray::set() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = VmRedis::coerceStringArg($frame->calledArgs[1], 'RedisArray::set', 0, 'key');
        $value = VmRedis::coerceValueToString($frame->calledArgs[2], 'RedisArray::set', 1, 'value');
        $socket = VmRedisArray::socketForKey($receiver, $key, 'RedisArray::set');
        VmRedisNative::set($socket, $key, $value);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class RedisArrayDel extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('del');
    }

    public function execute(Frame $frame): void
    {
        $receiver = VmRedisArray::requireReceiver($frame->calledArgs[0] ?? throw new \LogicException('RedisArray::del() called without $this'), 'RedisArray::del()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError('RedisArray::del() expects at least 1 argument, 0 given');
        }
        $total = 0;
        for ($i = 1; $i < \count($frame->calledArgs); ++$i) {
            $key = VmRedis::coerceStringArg($frame->calledArgs[$i], 'RedisArray::del', $i - 1, 'key');
            $socket = VmRedisArray::socketForKey($receiver, $key, 'RedisArray::del');
            $reply = VmRedisNative::command($socket, ['DEL', $key]);
            if (\is_int($reply)) {
                $total += $reply;
            }
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($total);
        }
    }
}

final class RedisArrayHosts extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('_hosts');
    }

    public function execute(Frame $frame): void
    {
        $receiver = VmRedisArray::requireReceiver($frame->calledArgs[0] ?? throw new \LogicException('RedisArray::_hosts() called without $this'), 'RedisArray::_hosts()');
        $state = VmRedisArray::state($receiver);
        if (null === $frame->returnVar) {
            return;
        }
        $list = [];
        foreach ($state->hosts as $h) {
            $list[] = $h['host'].':'.$h['port'];
        }
        $frame->returnVar->array(VmRedis::stringListToHashTable($list));
    }
}

final class RedisArrayTarget extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('_target');
    }

    public function execute(Frame $frame): void
    {
        $receiver = VmRedisArray::requireReceiver($frame->calledArgs[0] ?? throw new \LogicException('RedisArray::_target() called without $this'), 'RedisArray::_target()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('RedisArray::_target() expects at least 1 argument, 0 given');
        }
        $key = VmRedis::coerceStringArg($frame->calledArgs[1], 'RedisArray::_target', 0, 'key');
        $state = VmRedisArray::state($receiver);
        if ([] === $state->hosts) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $idx = VmRedisArray::hostIndex($state, $key);
        $h = $state->hosts[$idx];
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($h['host'].':'.$h['port']);
        }
    }
}
