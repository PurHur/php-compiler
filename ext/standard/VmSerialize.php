<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * serialize() / unserialize() hooks: __serialize/__unserialize (#1365),
 * legacy __sleep/__wakeup and Serializable (#3287).
 *
 * php-src: ext/standard/var.c, ext/standard/var_unserializer.c
 */
final class VmSerialize
{
    public static function serializeValue(Context $ctx, Variable $value): string
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT === $value->type) {
            $entry = $value->toObject();
            if (self::hasInstanceMethod($entry->class, '__serialize')) {
                $data = self::invokeSerialize($ctx, $entry);
                $exported = VmJson::export($data->resolveIndirect());
                if (!\is_array($exported)) {
                    throw new \LogicException('__serialize() must return an array');
                }

                return self::encodeCustomObject($entry->class->name, $exported);
            }
            if (self::implementsLegacySerializable($entry->class)) {
                $payload = self::invokeLegacySerializableSerialize($ctx, $entry);

                return self::encodeSerializableObject($entry->class->name, $payload);
            }
            if (self::hasInstanceMethod($entry->class, '__sleep')) {
                return self::encodeSleepObject($ctx, $entry);
            }
        }

        $exported = VmJson::export($value);
        $encoded = \serialize($exported);
        if (false === $encoded) {
            throw new \LogicException('serialize() failed');
        }

        return $encoded;
    }

    public static function unserializePayload(Context $ctx, string $payload): mixed
    {
        if (str_starts_with($payload, 'C:')) {
            $parsed = self::parseSerializableObjectPayload($payload);
            if (null === $parsed) {
                return false;
            }
            [$className, $data] = $parsed;
            $lc = strtolower($className);
            if (!isset($ctx->classes[$lc])) {
                $ctx->autoloadClass($className);
            }
            if (!isset($ctx->classes[$lc])) {
                return false;
            }
            $class = $ctx->classes[$lc];
            if (!self::implementsLegacySerializable($class)) {
                return false;
            }

            return self::instantiateLegacySerializable($ctx, $class, $data);
        }

        if (str_starts_with($payload, 'O:')) {
            $parsed = self::parseCustomObjectPayload($payload);
            if (null === $parsed) {
                return false;
            }
            [$className, $data] = $parsed;
            $lc = strtolower($className);
            if (!isset($ctx->classes[$lc])) {
                $ctx->autoloadClass($className);
            }
            if (!isset($ctx->classes[$lc])) {
                return false;
            }
            $class = $ctx->classes[$lc];
            if (self::hasInstanceMethod($class, '__unserialize')) {
                if (!\is_array($data)) {
                    return false;
                }

                return self::instantiateWithUnserialize($ctx, $class, $data);
            }
            if (self::hasInstanceMethod($class, '__wakeup')) {
                if (!\is_array($data)) {
                    return false;
                }

                return self::instantiateWithWakeup($ctx, $class, $data);
            }

            return false;
        }

        return @\unserialize($payload);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function encodeCustomObject(string $className, array $data): string
    {
        $inner = \serialize($data);
        if (!str_starts_with($inner, 'a:')) {
            throw new \LogicException('serialize() failed');
        }
        $len = \strlen($className);

        return 'O:'.$len.':"'.$className.'":'.\substr($inner, 2);
    }

    /** Zend Serializable custom object format: C:len:"Class":datalen:{payload} */
    public static function encodeSerializableObject(string $className, string $payload): string
    {
        $classLen = \strlen($className);
        $dataLen = \strlen($payload);

        return 'C:'.$classLen.':"'.$className.'":'.$dataLen.':{'.$payload.'}';
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public static function parseCustomObjectPayload(string $payload): ?array
    {
        if (!\preg_match('/^O:(\d+):"((?:[^"\\\\]|\\\\.)*)":(\d+):\{(.*)\}$/s', $payload, $m)) {
            return null;
        }
        $declaredLen = (int) $m[1];
        $className = stripcslashes($m[2]);
        if (\strlen($className) !== $declaredLen) {
            return null;
        }
        $arrayPayload = 'a:'.$m[3].':{'.$m[4].'}';
        $data = @\unserialize($arrayPayload);
        if (false === $data || !\is_array($data)) {
            return null;
        }

        return [$className, $data];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function parseSerializableObjectPayload(string $payload): ?array
    {
        if (!\preg_match('/^C:(\d+):"((?:[^"\\\\]|\\\\.)*)":(\d+):\{(.+)\}$/s', $payload, $m)) {
            return null;
        }
        $declaredLen = (int) $m[1];
        $className = stripcslashes($m[2]);
        if (\strlen($className) !== $declaredLen) {
            return null;
        }
        $dataLen = (int) $m[3];
        $data = $m[4];
        if (\strlen($data) !== $dataLen) {
            return null;
        }

        return [$className, $data];
    }

    public static function instantiateWithUnserialize(
        Context $ctx,
        ClassEntry $class,
        array $data
    ): Variable {
        $method = $class->methods['__unserialize'] ?? null;
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$class->name.'::__unserialize() must be a user method in this compiler build'
            );
        }
        $entry = new ObjectEntry($class);
        $recv = new Variable();
        $recv->object($entry);
        $dataVar = VmJson::import($data);
        $ctx->runtime->vm->invokePhpFunction($method, $recv, $dataVar);

        return $recv;
    }

    public static function instantiateWithWakeup(
        Context $ctx,
        ClassEntry $class,
        array $data
    ): Variable {
        $entry = new ObjectEntry($class);
        self::restoreObjectProperties($entry, $data);
        $method = $class->methods['__wakeup'] ?? null;
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$class->name.'::__wakeup() must be a user method in this compiler build'
            );
        }
        $recv = new Variable();
        $recv->object($entry);
        $ctx->runtime->vm->invokePhpFunction($method, $recv);

        return $recv;
    }

    public static function instantiateLegacySerializable(
        Context $ctx,
        ClassEntry $class,
        string $data
    ): Variable {
        $method = $class->methods['unserialize'] ?? null;
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$class->name.'::unserialize() must be a user method in this compiler build'
            );
        }
        $entry = new ObjectEntry($class);
        $recv = new Variable();
        $recv->object($entry);
        $dataVar = new Variable();
        $dataVar->string($data);
        $ctx->runtime->vm->invokePhpFunction($method, $recv, $dataVar);

        return $recv;
    }

    private static function encodeSleepObject(Context $ctx, ObjectEntry $entry): string
    {
        $names = self::invokeSleep($ctx, $entry);
        $props = [];
        foreach ($names as $name) {
            $props[$name] = $entry->getProperty($name)->resolveIndirect();
        }

        return self::encodeObjectPropertyBag($entry->class->name, $props);
    }

    /**
     * @param array<string, Variable> $props
     */
    private static function encodeObjectPropertyBag(string $className, array $props): string
    {
        $body = '';
        foreach ($props as $name => $value) {
            $body .= self::encodeSerializedScalar($name);
            $body .= self::encodeSerializedValue($value);
        }
        $count = \count($props);
        $classLen = \strlen($className);

        return 'O:'.$classLen.':"'.$className.'":'.$count.':{'.$body.'}';
    }

    private static function encodeSerializedValue(Variable $value): string
    {
        return self::encodeSerializedScalar(VmJson::export($value->resolveIndirect()));
    }

    private static function encodeSerializedScalar(mixed $exported): string
    {
        $encoded = \serialize($exported);
        if (false === $encoded) {
            throw new \LogicException('serialize() failed');
        }

        return $encoded;
    }

    /** @param array<string, mixed> $data */
    private static function restoreObjectProperties(ObjectEntry $entry, array $data): void
    {
        foreach ($data as $name => $raw) {
            $prop = $entry->getProperty((string) $name);
            $prop->copyFrom(VmJson::import($raw));
        }
    }

    private static function invokeSerialize(Context $ctx, ObjectEntry $entry): Variable
    {
        $method = $entry->class->methods['__serialize'] ?? null;
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$entry->class->name.'::__serialize() must be a user method in this compiler build'
            );
        }
        $recv = new Variable();
        $recv->object($entry);
        $result = $ctx->runtime->vm->invokePhpFunction($method, $recv);
        if (Variable::TYPE_ARRAY !== $result->type) {
            throw new \LogicException('__serialize() must return an array');
        }

        return $result;
    }

    /** @return list<string> */
    private static function invokeSleep(Context $ctx, ObjectEntry $entry): array
    {
        $method = $entry->class->methods['__sleep'] ?? null;
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$entry->class->name.'::__sleep() must be a user method in this compiler build'
            );
        }
        $recv = new Variable();
        $recv->object($entry);
        $result = $ctx->runtime->vm->invokePhpFunction($method, $recv);
        if (Variable::TYPE_ARRAY !== $result->type) {
            throw new \LogicException('__sleep() must return an array');
        }
        $names = [];
        foreach ($result->toArray()->iterateKeyed(true) as [, $elem]) {
            $elem = $elem->resolveIndirect();
            if (Variable::TYPE_STRING !== $elem->type) {
                throw new \LogicException('__sleep() must return an array of strings');
            }
            $names[] = $elem->toString();
        }

        return $names;
    }

    private static function invokeLegacySerializableSerialize(Context $ctx, ObjectEntry $entry): string
    {
        $method = $entry->class->methods['serialize'] ?? null;
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$entry->class->name.'::serialize() must be a user method in this compiler build'
            );
        }
        $recv = new Variable();
        $recv->object($entry);
        $result = $ctx->runtime->vm->invokePhpFunction($method, $recv);
        if (Variable::TYPE_STRING !== $result->type) {
            throw new \LogicException('Serializable::serialize() must return a string');
        }

        return $result->toString();
    }

    private static function implementsLegacySerializable(ClassEntry $class): bool
    {
        if (!\in_array('serializable', $class->interfaces, true)) {
            return false;
        }

        return isset($class->methods['serialize'], $class->methods['unserialize']);
    }

    private static function hasInstanceMethod(ClassEntry $class, string $methodName): bool
    {
        return isset($class->methods[strtolower($methodName)]);
    }
}
