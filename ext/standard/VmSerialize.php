<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * __serialize / __unserialize magic methods for serialize() and unserialize() (issue #1365).
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
        }

        $exported = VmJson::export($value);
        $encoded = \serialize($exported);
        if (false === $encoded) {
            throw new \LogicException('serialize() failed');
        }

        return $encoded;
    }

    /**
     * @param array<string, mixed>|null $options unserialize() options (allowed_classes only; #3300)
     */
    public static function unserializePayload(Context $ctx, string $payload, ?array $options = null): mixed
    {
        if (str_starts_with($payload, 'O:')) {
            $parsed = self::parseCustomObjectPayload($payload);
            if (null === $parsed) {
                return false;
            }
            [$className, $data] = $parsed;
            if (!self::isClassAllowedForUnserialize($className, $options)) {
                return false;
            }
            $lc = strtolower($className);
            if (!isset($ctx->classes[$lc])) {
                $ctx->autoloadClass($className);
            }
            if (!isset($ctx->classes[$lc])) {
                return false;
            }
            $class = $ctx->classes[$lc];
            if (!self::hasInstanceMethod($class, '__unserialize')) {
                return false;
            }

            return self::instantiateWithUnserialize($ctx, $class, $data);
        }

        return @\unserialize($payload);
    }

    /**
     * @param array<string, mixed>|null $options
     */
    public static function isClassAllowedForUnserialize(string $className, ?array $options): bool
    {
        if (null === $options || !\array_key_exists('allowed_classes', $options)) {
            return true;
        }
        $allowed = $options['allowed_classes'];
        if (false === $allowed) {
            return false;
        }
        if (true === $allowed) {
            return true;
        }
        if (!\is_array($allowed)) {
            throw new \LogicException('allowed_classes must be of type bool or array');
        }
        foreach ($allowed as $entry) {
            if (\is_string($entry) && 0 === \strcasecmp($entry, $className)) {
                return true;
            }
        }

        return false;
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

    private static function hasInstanceMethod(ClassEntry $class, string $methodName): bool
    {
        return isset($class->methods[strtolower($methodName)]);
    }
}
