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

    public const REFLECTION_PROPERTY = 'reflectionproperty';

    public const REFLECTION_FUNCTION = 'reflectionfunction';

    public const REFLECTION_CONSTANT = 'reflectionconstant';

    public const REFLECTION_ATTRIBUTE = 'reflectionattribute';

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

    public const PROP_FUNC_NAME = 'funcName';

    public const PROP_PARAM_INDEX = 'paramIndex';

    public const PROP_PARAM_NAME = 'paramName';

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

    public static function constantNameFromReflection(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_CONSTANT_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionConstant missing constant name');
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
