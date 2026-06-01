<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * Build ReflectionAttribute stubs from compile-time metadata (#1936, #3206, #3340, #3800).
 */
final class ReflectionSupport
{
    public const REFLECTION_CLASS = 'reflectionclass';

    public const REFLECTION_METHOD = 'reflectionmethod';

    public const REFLECTION_PROPERTY = 'reflectionproperty';

    public const REFLECTION_FUNCTION = 'reflectionfunction';

    public const REFLECTION_CONSTANT = 'reflectionconstant';

    public const REFLECTION_ATTRIBUTE = 'reflectionattribute';

    public const REFLECTION_ENUM_UNIT_CASE = 'reflectionenumunitcase';

    public const REFLECTION_PARAMETER = 'reflectionparameter';

    public const REFLECTION_NAMED_TYPE = 'reflectionnamedtype';

    public const REFLECTION_UNION_TYPE = 'reflectionuniontype';

    public const REFLECTION_INTERSECTION_TYPE = 'reflectionintersectiontype';

    public const PROP_CLASS_NAME = 'name';

    public const PROP_METHOD_NAME = 'method';

    public const PROP_PROPERTY_NAME = 'property';

    public const PROP_FUNCTION_NAME = 'function';

    public const PROP_CONSTANT_NAME = 'constant';

    public const PROP_ATTR_NAME = 'name';

    /** Serialized attribute ctor args on ReflectionAttribute instances (#3206). */
    public const PROP_ATTR_ARGS = 'args';

    public const PROP_ENUM_CASE_NAME = 'case';

    public const PROP_FUNC_NAME = 'funcName';

    public const PROP_PARAM_INDEX = 'paramIndex';

    public const PROP_PARAM_NAME = 'paramName';

    public const PROP_PARAM_POSITION = 'position';

    public const PROP_TYPE_NAME = 'typeName';

    public const PROP_TYPE_BUILTIN = 'typeBuiltin';

    public const PROP_TYPE_STRING = 'typeString';

    public const PROP_TYPE_ALLOWS_NULL = 'allowsNullFlag';

    public const PROP_TYPE_MEMBERS = 'typeMembers';

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

    /**
     * Build PHP-style getArguments() array from stored ctor arg specs.
     *
     * @param list<array{name: ?string, value: mixed}> $args
     */
    public static function argumentsArray(array $args): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($args as $spec) {
            $val = self::scalarToVariable($spec['value']);
            if (null !== $spec['name']) {
                $ht->add($spec['name'], $val);
            } else {
                $ht->append($val);
            }
        }

        return $result;
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
     * Map stored attribute ctor args to invokePhpFunction arguments in parameter order (#3216).
     *
     * @param list<array{name: ?string, value: mixed}> $argSpecs
     *
     * @return list<Variable>
     */
    public static function constructorInvokeVariables(\PHPCompiler\Func\PHP $ctor, array $argSpecs): array
    {
        $positional = [];
        $named = [];
        foreach ($argSpecs as $spec) {
            if (null !== $spec['name']) {
                $named[$spec['name']] = $spec['value'];
            } else {
                $positional[] = $spec['value'];
            }
        }
        $vars = [];
        $pi = 0;
        foreach ($ctor->block->paramNames as $paramName) {
            if (isset($named[$paramName])) {
                $vars[] = self::scalarToVariable($named[$paramName]);
            } elseif (array_key_exists($pi, $positional)) {
                $vars[] = self::scalarToVariable($positional[$pi++]);
            } else {
                $null = new Variable();
                $null->null();
                $vars[] = $null;
            }
        }

        return $vars;
    }

    /**
     * Sync promoted / declared instance properties from attribute ctor args (#3216).
     *
     * VM ctor promotion assignments can fail when invoked from builtins; Zend sets properties in __construct.
     *
     * @param list<array{name: ?string, value: mixed}> $argSpecs
     */
    public static function applyConstructorPropertyArgs(ObjectEntry $object, \PHPCompiler\Func\PHP $ctor, array $argSpecs): void
    {
        $positional = [];
        $named = [];
        foreach ($argSpecs as $spec) {
            if (null !== $spec['name']) {
                $named[$spec['name']] = $spec['value'];
            } else {
                $positional[] = $spec['value'];
            }
        }
        $pi = 0;
        foreach ($ctor->block->paramNames as $paramName) {
            if (isset($named[$paramName])) {
                $value = $named[$paramName];
            } elseif (array_key_exists($pi, $positional)) {
                $value = $positional[$pi++];
            } else {
                continue;
            }
            if ($object->hasProperty($paramName)) {
                $object->getProperty($paramName)->copyFrom(self::scalarToVariable($value));
            }
        }
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

    public static function requireReflectionParameter(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionParameter method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_PARAMETER) {
            throw new \LogicException('Expected ReflectionParameter instance');
        }

        return $obj;
    }

    public static function requireReflectionEnumUnitCase(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionEnumUnitCase method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_ENUM_UNIT_CASE) {
            throw new \LogicException('Expected ReflectionEnumUnitCase instance');
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

    public static function enumCaseNameFromReflection(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_ENUM_CASE_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionEnumUnitCase missing case name');
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

    public static function requireReflectionProperty(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionProperty method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_PROPERTY) {
            throw new \LogicException('Expected ReflectionProperty instance');
        }

        return $obj;
    }

    public static function requireReflectionFunction(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionFunction method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_FUNCTION) {
            throw new \LogicException('Expected ReflectionFunction instance');
        }

        return $obj;
    }

    public static function requireReflectionConstant(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionConstant method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_CONSTANT) {
            throw new \LogicException('Expected ReflectionConstant instance');
        }

        return $obj;
    }

    public static function propertyNameFromReflection(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_PROPERTY_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionProperty missing property name');
        }

        return $nameVar->toString();
    }

    public static function functionNameFromReflection(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_FUNC_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            $nameVar = $reflection->getProperty(self::PROP_FUNCTION_NAME)->resolveIndirect();
        }
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionFunction missing function name');
        }

        return $nameVar->toString();
    }

    public static function isReflectionFunctionAnonymous(ObjectEntry $reflection): bool
    {
        $state = $reflection->reflectionClosureState;

        return null !== $state && $state->isUserClosure();
    }

    /**
     * @return \PHPCompiler\Func\PHP
     */
    public static function resolveFunctionFromReflection(Context $ctx, ObjectEntry $reflection): \PHPCompiler\Func\PHP
    {
        $closure = $reflection->reflectionClosureState;
        if (null !== $closure) {
            return $closure->func;
        }

        return self::resolveUserFunction($ctx, self::functionNameFromReflection($reflection));
    }

    public static function constantNameFromReflection(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_CONSTANT_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionConstant missing constant name');
        }

        return $nameVar->toString();
    }

    public static function paramPositionFromReflection(ObjectEntry $reflection): int
    {
        $posVar = $reflection->getProperty(self::PROP_PARAM_POSITION)->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $posVar->type) {
            throw new \LogicException('ReflectionParameter missing position');
        }

        return $posVar->toInt();
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

    public static function requireReflectionType(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionType method called without object');
        }
        $obj = $receiver->toObject();
        if (!ReflectionTypeSupport::isReflectionTypeClass(strtolower($obj->class->name))) {
            throw new \LogicException('Expected ReflectionType instance');
        }

        return $obj;
    }

    public static function paramIndexFromReflection(ObjectEntry $reflection): int
    {
        $idxVar = $reflection->getProperty(self::PROP_PARAM_INDEX)->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $idxVar->type) {
            throw new \LogicException('ReflectionParameter missing parameter index');
        }

        return $idxVar->toInt();
    }

    /**
     * @return \PHPCompiler\Func\PHP
     */
    public static function resolveUserFunction(Context $ctx, string $functionName): \PHPCompiler\Func\PHP
    {
        $lc = strtolower($functionName);
        $func = $ctx->functions[$lc] ?? null;
        if (!$func instanceof \PHPCompiler\Func\PHP) {
            throw new \LogicException("Function {$functionName}() does not exist");
        }

        return $func;
    }

    public static function typeStringFromReflection(ObjectEntry $reflection): string
    {
        $stored = $reflection->getProperty(self::PROP_TYPE_STRING)->resolveIndirect();
        if (Variable::TYPE_STRING !== $stored->type) {
            throw new \LogicException('ReflectionType missing type string');
        }

        return $stored->toString();
    }

    public static function allowsNullFromReflection(ObjectEntry $reflection): bool
    {
        $flag = $reflection->getProperty(self::PROP_TYPE_ALLOWS_NULL)->resolveIndirect();

        return $flag->toBool();
    }
}
