<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
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
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['del'])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('Redis');
        $entry->isInternal = true;
        foreach (RedisConstants::CLASS_CONSTANTS as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$name] = $const;
            $entry->constNames[$name] = RedisConstants::CLASS_CONSTANT_NAMES[$name];
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->constructor = new RedisConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $methods = [
            'connect' => new RedisConnect(),
            'set' => new RedisSet(),
            'get' => new RedisGet(),
            'close' => new RedisClose(),
            'del' => new RedisDel(),
            'exists' => new RedisExists(),
            'ping' => new RedisPing(),
            'auth' => new RedisAuth(),
            'select' => new RedisSelect(),
            'isconnected' => new RedisIsConnected(),
            'hset' => new RedisHSet(),
            'hget' => new RedisHGet(),
            'hgetall' => new RedisHGetAll(),
        ];
        foreach ($methods as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
            $entry->methodNames[$name] = match ($name) {
                'isconnected' => 'isConnected',
                'hset' => 'hSet',
                'hget' => 'hGet',
                'hgetall' => 'hGetAll',
                default => $name,
            };
        }

        $ctx->classes[self::CLASS_LC] = $entry;
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
            throw new \RedisException(\sprintf('%s(): Connection lost or not established', $label));
        }

        return $state->socket;
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
