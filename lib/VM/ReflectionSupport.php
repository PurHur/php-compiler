<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * Build ReflectionAttribute stubs from compile-time metadata (#1936, #3206).
 */
final class ReflectionSupport
{
    public const REFLECTION_CLASS = 'reflectionclass';

    public const REFLECTION_METHOD = 'reflectionmethod';

    public const REFLECTION_ATTRIBUTE = 'reflectionattribute';

    public const PROP_CLASS_NAME = 'name';

    public const PROP_METHOD_NAME = 'method';

    public const PROP_ATTR_NAME = 'name';

    /** Serialized attribute ctor args on ReflectionAttribute instances (#3206). */
    public const PROP_ATTR_ARGS = 'args';

    /**
     * @param list<string> $names
     */
    public static function attributesArray(Frame $frame, array $names): Variable
    {
        $entries = [];
        foreach ($names as $name) {
            $entries[] = new AttributeEntry($name);
        }

        return self::attributesArrayFromEntries($frame, $entries);
    }

    /**
     * @param list<AttributeEntry> $entries
     */
    public static function attributesArrayFromEntries(Frame $frame, array $entries): Variable
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
        foreach ($entries as $entry) {
            if (!$entry instanceof AttributeEntry) {
                continue;
            }
            $obj = new ObjectEntry($attrClass);
            $obj->constructed = true;
            $obj->getProperty(self::PROP_ATTR_NAME)->string($entry->name);
            $obj->getProperty(self::PROP_ATTR_ARGS)->copyFrom(self::argsToVariable($entry->args));
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($obj);
            $ht->append($slot);
        }

        return $result;
    }

    /**
     * @param list<array{name: ?string, value: mixed}> $args
     */
    public static function argsToVariable(array $args): Variable
    {
        $arr = new Variable();
        $arr->newArray();
        $ht = $arr->toArray();
        foreach ($args as $spec) {
            $entry = new Variable();
            $entry->newArray();
            $entryHt = $entry->toArray();
            $nameVal = new Variable();
            if (null === $spec['name']) {
                $nameVal->null();
            } else {
                $nameVal->string($spec['name']);
            }
            $entryHt->add('name', $nameVal);
            $entryHt->add('value', self::scalarToVariable($spec['value']));
            $ht->append($entry);
        }

        return $arr;
    }

    public static function scalarToVariable(mixed $value): Variable
    {
        $var = new Variable();
        if (null === $value) {
            $var->null();
        } elseif (is_bool($value)) {
            $var->bool($value);
        } elseif (is_int($value)) {
            $var->int($value);
        } elseif (is_float($value)) {
            $var->float($value);
        } elseif (is_string($value)) {
            $var->string($value);
        } else {
            throw new \LogicException('Unsupported attribute argument type in this compiler build');
        }

        return $var;
    }

    /**
     * @return list<array{name: ?string, value: mixed}>
     */
    public static function argsFromReflectionObject(ObjectEntry $attr): array
    {
        $argsVar = $attr->getProperty(self::PROP_ATTR_ARGS)->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $argsVar->type) {
            return [];
        }
        $out = [];
        foreach ($argsVar->toArray()->iterateKeyed(true) as [, $entryVar]) {
            $entry = $entryVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $entry->type) {
                continue;
            }
            $name = null;
            $value = null;
            foreach ($entry->toArray()->iterateKeyed(true) as [$k, $v]) {
                $key = $k->resolveIndirect();
                if (Variable::TYPE_STRING !== $key->type) {
                    continue;
                }
                $resolved = $v->resolveIndirect();
                if ('name' === $key->toString()) {
                    $name = Variable::TYPE_NULL === $resolved->type ? null : $resolved->toString();
                } elseif ('value' === $key->toString()) {
                    $value = self::variableToScalar($resolved);
                }
            }
            $out[] = ['name' => $name, 'value' => $value];
        }

        return $out;
    }

    public static function variableToScalar(Variable $var): mixed
    {
        $var = $var->resolveIndirect();
        return match ($var->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_BOOLEAN => $var->toBool(),
            Variable::TYPE_INTEGER => $var->toInt(),
            Variable::TYPE_FLOAT => $var->toFloat(),
            Variable::TYPE_STRING => $var->toString(),
            default => throw new \LogicException('Unsupported attribute argument type in this compiler build'),
        };
    }

    public static function requireReflectionAttribute(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionAttribute method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_ATTRIBUTE) {
            throw new \LogicException('Expected ReflectionAttribute instance');
        }

        return $obj;
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
     * @param list<AttributeEntry> $all
     *
     * @return list<AttributeEntry>
     */
    public static function filterEntriesByName(array $all, ?string $filter): array
    {
        if (null === $filter || '' === $filter) {
            return $all;
        }
        $want = strtolower(ltrim($filter, '\\'));
        $out = [];
        foreach ($all as $entry) {
            if (!$entry instanceof AttributeEntry) {
                continue;
            }
            $cand = strtolower(ltrim($entry->name, '\\'));
            if ($cand === $want || str_ends_with($cand, '\\'.$want)) {
                $out[] = $entry;
            }
        }

        return $out;
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
