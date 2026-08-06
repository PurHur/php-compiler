<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/**
 * Redis VM class (PECL phpredis redis.c; #6098).
 */
final class VmRedis
{
    public const CLASS_LC = 'redis';

    /** @var array<int, RedisState> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['publish'])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('Redis');
        $entry->isInternal = true;
        // Declared casing is the storage key (ClassConstName / #25929). Do not
        // lowercase — Redis::OPT_SERIALIZER / defined('Redis::OPT_SERIALIZER') (#28099).
        foreach (RedisConstants::CLASS_CONSTANTS as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $canonical = RedisConstants::CLASS_CONSTANT_NAMES[$name];
            $entry->constants[$canonical] = $const;
            $entry->constNames[$canonical] = $canonical;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->constructor = new RedisConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        require_once __DIR__.'/RedisDepth20682.php';
        require_once __DIR__.'/RedisOptions.php';
        require_once __DIR__.'/RedisIntrospection.php';
        $methods = [
            'connect' => new RedisConnect(),
            'set' => new RedisSet(),
            'get' => new RedisGet(),
            'close' => new RedisClose(),
            'setoption' => new RedisSetOption(),
            'getoption' => new RedisGetOption(),
            'gethost' => new RedisGetHost(),
            'getport' => new RedisGetPort(),
            'getdbnum' => new RedisGetDBNum(),
            'gettimeout' => new RedisGetTimeout(),
            'getreadtimeout' => new RedisGetReadTimeout(),
            'getpersistentid' => new RedisGetPersistentID(),
            'getauth' => new RedisGetAuth(),
            'getlasterror' => new RedisGetLastError(),
            'clearlasterror' => new RedisClearLastError(),
            'getmode' => new RedisGetMode(),
            'del' => new RedisDel(),
            'exists' => new RedisExists(),
            'ping' => new RedisPing(),
            'auth' => new RedisAuth(),
            'select' => new RedisSelect(),
            'isconnected' => new RedisIsConnected(),
            'hset' => new RedisHSet(),
            'hget' => new RedisHGet(),
            'hgetall' => new RedisHGetAll(),
            'lpush' => new RedisLPush(),
            'lpop' => new RedisLPop(),
            'rpush' => new RedisRPush(),
            'rpop' => new RedisRPop(),
            'lrange' => new RedisLRange(),
            'sadd' => new RedisSAdd(),
            'srem' => new RedisSRem(),
            'smembers' => new RedisSMembers(),
            'sismember' => new RedisSIsMember(),
            'zadd' => new RedisZAdd(),
            'zrange' => new RedisZRange(),
            'zrem' => new RedisZRem(),
            'multi' => new RedisMulti(),
            'pipeline' => new RedisPipeline(),
            'exec' => new RedisExec(),
            'eval' => new RedisEval(),
            'expire' => new RedisExpire(),
            'ttl' => new RedisTtl(),
            'incr' => new RedisIncr(),
            'decr' => new RedisDecr(),
            'keys' => new RedisKeys(),
            'mget' => new RedisMGet(),
            'mset' => new RedisMSet(),
            // #20682 — pub/sub, SCAN family, streams, companions
            'publish' => new RedisPublish(),
            'subscribe' => new RedisSubscribe(),
            'psubscribe' => new RedisPSubscribe(),
            'scan' => new RedisScan(),
            'hscan' => new RedisHScan(),
            'sscan' => new RedisSScan(),
            'zscan' => new RedisZScan(),
            'xadd' => new RedisXAdd(),
            'xread' => new RedisXRead(),
            'xgroup' => new RedisXGroup(),
            'pconnect' => new RedisPConnect(),
            'rawcommand' => new RedisRawCommand(),
            'setex' => new RedisSetEx(),
            'setnx' => new RedisSetNx(),
            'blpop' => new RedisBlPop(),
            'brpop' => new RedisBrPop(),
            'info' => new RedisInfo(),
            'flushall' => new RedisFlushAll(),
            'watch' => new RedisWatch(),
            'unwatch' => new RedisUnwatch(),
        ];
        foreach ($methods as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
            $entry->methodNames[$name] = match ($name) {
                'isconnected' => 'isConnected',
                'setoption' => 'setOption',
                'getoption' => 'getOption',
                'gethost' => 'getHost',
                'getport' => 'getPort',
                'getdbnum' => 'getDBNum',
                'gettimeout' => 'getTimeout',
                'getreadtimeout' => 'getReadTimeout',
                'getpersistentid' => 'getPersistentID',
                'getauth' => 'getAuth',
                'getlasterror' => 'getLastError',
                'clearlasterror' => 'clearLastError',
                'getmode' => 'getMode',
                'hset' => 'hSet',
                'hget' => 'hGet',
                'hgetall' => 'hGetAll',
                'lpush' => 'lPush',
                'lpop' => 'lPop',
                'rpush' => 'rPush',
                'rpop' => 'rPop',
                'lrange' => 'lRange',
                'sadd' => 'sAdd',
                'srem' => 'sRem',
                'smembers' => 'sMembers',
                'sismember' => 'sIsMember',
                'zadd' => 'zAdd',
                'zrange' => 'zRange',
                'zrem' => 'zRem',
                'psubscribe' => 'psubscribe',
                'hscan' => 'hScan',
                'sscan' => 'sScan',
                'zscan' => 'zScan',
                'xadd' => 'xAdd',
                'xread' => 'xRead',
                'xgroup' => 'xGroup',
                'pconnect' => 'pconnect',
                'rawcommand' => 'rawCommand',
                'setex' => 'setEx',
                'setnx' => 'setNx',
                'blpop' => 'blPop',
                'brpop' => 'brPop',
                'flushall' => 'flushAll',
                default => $name,
            };
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /** Build a packed PHP list HashTable from string values (#20612). */
    public static function stringListToHashTable(array $values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $v) {
            $slot = new Variable();
            $slot->string((string) $v);
            $ht->append($slot);
        }

        return $ht;
    }

    /** Build a map HashTable from string=>string (#20612). */
    public static function stringMapToHashTable(array $map): HashTable
    {
        $ht = new HashTable();
        foreach ($map as $k => $v) {
            $slot = new Variable();
            $slot->string((string) $v);
            $ht->add((string) $k, $slot);
        }

        return $ht;
    }

    /** Convert a RESP reply into a VM Variable (MULTI/EXEC / EVAL) (#20612). */
    public static function replyToVariable(mixed $reply): Variable
    {
        $slot = new Variable();
        if (null === $reply) {
            $slot->null();

            return $slot;
        }
        if (\is_int($reply)) {
            $slot->int($reply);

            return $slot;
        }
        if (\is_bool($reply)) {
            $slot->bool($reply);

            return $slot;
        }
        if (\is_string($reply)) {
            $slot->string($reply);

            return $slot;
        }
        if (\is_array($reply)) {
            $ht = new HashTable();
            $isList = \array_keys($reply) === \range(0, \count($reply) - 1);
            if ($isList || [] === $reply) {
                foreach ($reply as $item) {
                    $ht->append(self::replyToVariable($item));
                }
            } else {
                foreach ($reply as $k => $item) {
                    $ht->add((string) $k, self::replyToVariable($item));
                }
            }
            $slot->array($ht);

            return $slot;
        }
        $slot->string((string) $reply);

        return $slot;
    }

    /**
     * @return list<string>
     */
    public static function coerceStringListArg(Variable $var, string $label, int $index, string $paramName): array
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array, %s given',
                $label,
                $index + 1,
                $paramName,
                self::typeLabel($var)
            ));
        }
        $out = [];
        foreach ($resolved->toArray()->iterateKeyed(true) as [, $valueVar]) {
            $out[] = self::coerceValueToString($valueVar, $label, $index, $paramName);
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function coerceStringMapArg(Variable $var, string $label, int $index, string $paramName): array
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array, %s given',
                $label,
                $index + 1,
                $paramName,
                self::typeLabel($var)
            ));
        }
        $out = [];
        foreach ($resolved->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $keyResolved = $keyVar->resolveIndirect();
            $key = match ($keyResolved->type) {
                Variable::TYPE_STRING => $keyResolved->toString(),
                Variable::TYPE_INTEGER => (string) $keyResolved->toInt(),
                default => self::coerceValueToString($keyVar, $label, $index, $paramName),
            };
            $out[$key] = self::coerceValueToString($valueVar, $label, $index, $paramName);
        }

        return $out;
    }

    public static function initObject(ObjectEntry $entry): void
    {
        self::$store[$entry->id] = new RedisState();
        $entry->constructed = true;
    }

    public static function requireReceiver(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf('%s must be called on Redis, %s given', $label, self::typeLabel($var)));
        }
        $object = $var->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf('%s must be called on Redis, %s given', $label, $object->class->name));
        }
        if (!$object->constructed) {
            throw new \TypeError(\sprintf('%s must be called on Redis, uninitialized %s given', $label, $object->class->name));
        }

        return $object;
    }

    public static function state(ObjectEntry $entry): RedisState
    {
        $state = self::$store[$entry->id] ?? null;
        if (null === $state) {
            self::initObject($entry);
            $state = self::$store[$entry->id] ?? null;
        }
        if (null === $state) {
            throw new \LogicException('Redis internal state missing in this compiler build');
        }

        return $state;
    }

    /**
     * @return resource
     *
     * @throws \RedisException
     */
    public static function requireSocket(ObjectEntry $entry, string $label)
    {
        $state = self::state($entry);
        if (!$state->connected || null === $state->socket) {
            $message = \sprintf('%s(): Connection lost or not established', $label);
            self::noteError($entry, $message);
            throw new \RedisException($message);
        }

        return $state->socket;
    }

    public static function noteError(ObjectEntry $entry, string $message): void
    {
        self::state($entry)->lastError = $message;
    }

    public static function coerceStringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        return VmString::coerceStringBuiltinArg($var, $label, $index, $paramName);
    }

    public static function coerceIntArg(Variable $var, string $label, int $index, string $paramName, int $default = 0): int
    {
        if (Variable::TYPE_NULL === $var->resolveIndirect()->type) {
            return $default;
        }
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return $resolved->toInt();
        }
        if (Variable::TYPE_FLOAT === $resolved->type) {
            return (int) $resolved->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_STRING === $resolved->type) {
            $raw = $resolved->toString();
            if ('' === $raw) {
                return 0;
            }
            if (\is_numeric($raw)) {
                return (int) $raw;
            }
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $label,
            $index + 1,
            $paramName,
            self::typeLabel($var)
        ));
    }

    public static function coerceFloatArg(Variable $var, string $label, int $index, string $paramName, float $default = 0.0): float
    {
        if (Variable::TYPE_NULL === $var->resolveIndirect()->type) {
            return $default;
        }
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_FLOAT === $resolved->type) {
            return $resolved->toFloat();
        }
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return (float) $resolved->toInt();
        }
        if (Variable::TYPE_STRING === $resolved->type && \is_numeric($resolved->toString())) {
            return (float) $resolved->toString();
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type float, %s given',
            $label,
            $index + 1,
            $paramName,
            self::typeLabel($var)
        ));
    }

    public static function coerceValueToString(Variable $var, string $label, int $index, string $paramName): string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_STRING === $resolved->type) {
            return $resolved->toString();
        }
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return (string) $resolved->toInt();
        }
        if (Variable::TYPE_FLOAT === $resolved->type) {
            return (string) $resolved->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool() ? '1' : '';
        }
        if (Variable::TYPE_NULL === $resolved->type) {
            return '';
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type string, %s given',
            $label,
            $index + 1,
            $paramName,
            self::typeLabel($var)
        ));
    }

    private static function typeLabel(Variable $var): string
    {
        $var = $var->resolveIndirect();

        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'unknown',
        };
    }
}
