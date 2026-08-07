<?php

declare(strict_types=1);

namespace PHPCompiler\ext\memcached;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/**
 * Memcached VM class (PECL php-memcached; #6099).
 */
final class VmMemcached
{
    public const CLASS_LC = 'memcached';

    /** @var array<int, MemcachedState> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['getresultcode'])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('Memcached');
        $entry->isInternal = true;
        foreach (MemcachedConstants::CLASS_CONSTANTS as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$name] = $const;
            $entry->constNames[$name] = MemcachedConstants::CLASS_CONSTANT_NAMES[$name];
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->constructor = new MemcachedConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        require_once __DIR__.'/MemcachedDepthMethods.php';
        $methods = [
            'addserver' => new MemcachedAddServer(),
            'set' => new MemcachedSet(),
            'get' => new MemcachedGet(),
            'delete' => new MemcachedDelete(),
            'getresultcode' => new MemcachedGetResultCode(),
            // #27874 depth surface
            'add' => new MemcachedAdd(),
            'replace' => new MemcachedReplace(),
            'append' => new MemcachedAppend(),
            'prepend' => new MemcachedPrepend(),
            'increment' => new MemcachedIncrement(),
            'decrement' => new MemcachedDecrement(),
            'flush' => new MemcachedFlush(),
            'getmulti' => new MemcachedGetMulti(),
            'setmulti' => new MemcachedSetMulti(),
            'deletemulti' => new MemcachedDeleteMulti(),
            'touch' => new MemcachedTouch(),
            'cas' => new MemcachedCas(),
        ];
        foreach ($methods as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
            $entry->methodNames[$name] = match ($name) {
                'addserver' => 'addServer',
                'getresultcode' => 'getResultCode',
                'getmulti' => 'getMulti',
                'setmulti' => 'setMulti',
                'deletemulti' => 'deleteMulti',
                default => $name,
            };
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function initObject(ObjectEntry $entry): void
    {
        self::$store[$entry->id] = new MemcachedState();
        $entry->constructed = true;
    }

    public static function requireReceiver(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf('%s must be called on Memcached, %s given', $label, self::typeLabel($var)));
        }
        $object = $var->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf('%s must be called on Memcached, %s given', $label, $object->class->name));
        }
        if (!$object->constructed) {
            throw new \TypeError(\sprintf('%s must be called on Memcached, uninitialized %s given', $label, $object->class->name));
        }

        return $object;
    }

    public static function state(ObjectEntry $entry): MemcachedState
    {
        $state = self::$store[$entry->id] ?? null;
        if (null === $state) {
            self::initObject($entry);
            $state = self::$store[$entry->id] ?? null;
        }
        if (null === $state) {
            throw new \LogicException('Memcached state missing');
        }

        return $state;
    }

    /**
     * Lazy-connect to the first configured server (php-memcached addServer defers connect).
     *
     * @return resource|null
     */
    public static function ensureSocket(ObjectEntry $entry, string $label)
    {
        $state = self::state($entry);
        if (null !== $state->socket && \is_resource($state->socket)) {
            return $state->socket;
        }
        if ([] === $state->servers) {
            $state->resultCode = MemcachedConstants::RES_NO_SERVERS;

            return null;
        }
        $server = $state->servers[0];
        try {
            $socket = VmMemcachedNative::connect($server['host'], $server['port'], 1.0);
        } catch (\RuntimeException $e) {
            $state->resultCode = MemcachedConstants::RES_CONNECTION_FAILURE;

            return null;
        }
        $state->socket = $socket;
        $state->connectedHost = $server['host'];
        $state->connectedPort = $server['port'];

        return $socket;
    }

    public static function coerceStringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        return VmString::coerceStringBuiltinArg($var, $label, $index, $paramName);
    }

    public static function coerceIntArg(Variable $var, string $label, int $index, string $paramName, int $default = 0): int
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return $default;
        }
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
            return (int) $resolved->toString();
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $label,
            $index + 1,
            $paramName,
            self::typeLabel($var)
        ));
    }

    public static function coerceValueToString(Variable $var, string $label, int $index, string $paramName): string
    {
        $resolved = $var->resolveIndirect();

        return match ($resolved->type) {
            Variable::TYPE_STRING => $resolved->toString(),
            Variable::TYPE_INTEGER => (string) $resolved->toInt(),
            Variable::TYPE_FLOAT => (string) $resolved->toFloat(),
            Variable::TYPE_BOOLEAN => $resolved->toBool() ? '1' : '',
            Variable::TYPE_NULL => '',
            default => throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be string-coercible, %s given',
                $label,
                $index + 1,
                $paramName,
                self::typeLabel($var)
            )),
        };
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

    private static function typeLabel(Variable $var): string
    {
        $resolved = $var->resolveIndirect();

        return match ($resolved->type) {
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
