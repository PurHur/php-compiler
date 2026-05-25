<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * Build ReflectionAttribute stubs from compile-time metadata (#1936).
 */
final class ReflectionSupport
{
    public const REFLECTION_CLASS = 'reflectionclass';

    public const REFLECTION_METHOD = 'reflectionmethod';

    public const REFLECTION_ATTRIBUTE = 'reflectionattribute';

    public const PROP_CLASS_NAME = 'name';

    public const PROP_METHOD_NAME = 'method';

    public const PROP_ATTR_NAME = 'name';

    /**
     * @param list<string> $names
     */
    public static function attributesArray(Frame $frame, array $names): Variable
    {
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('Reflection requires VM context');
        }
        $attrClass = $ctx->classes[self::REFLECTION_ATTRIBUTE] ?? null;
        if (null === $attrClass) {
            throw new \LogicException('ReflectionAttribute is not registered in this compiler build');
        }
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($names as $name) {
            $obj = new ObjectEntry($attrClass);
            $obj->constructed = true;
            $obj->getProperty(self::PROP_ATTR_NAME)->string($name);
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($obj);
            $ht->append($slot);
        }

        return $result;
    }

    public static function requireReflectionClass(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionClass method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_CLASS) {
            throw new \LogicException('Expected ReflectionClass instance');
        }

        return $obj;
    }

    public static function requireReflectionMethod(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionMethod method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_METHOD) {
            throw new \LogicException('Expected ReflectionMethod instance');
        }

        return $obj;
    }

    public static function classNameFromReflection(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_CLASS_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionClass missing target class name');
        }

        return $nameVar->toString();
    }

    public static function methodNameFromReflection(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_METHOD_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionMethod missing method name');
        }

        return $nameVar->toString();
    }

    /**
     * @param list<string> $all
     *
     * @return list<string>
     */
    public static function filterByName(array $all, ?string $filter): array
    {
        if (null === $filter || '' === $filter) {
            return $all;
        }
        $want = strtolower(ltrim($filter, '\\'));
        $out = [];
        foreach ($all as $name) {
            $cand = strtolower(ltrim($name, '\\'));
            if ($cand === $want || str_ends_with($cand, '\\'.$want)) {
                $out[] = $name;
            }
        }

        return $out;
    }
}
